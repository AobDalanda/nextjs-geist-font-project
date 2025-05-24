<?php

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\Doctor;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\DoctorRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Dompdf\Dompdf;
use Twig\Environment;

class ExportService
{
    private $twig;
    private $security;
    private $appointmentRepository;
    private $doctorRepository;
    private $userRepository;
    private $statisticsService;

    public function __construct(
        Environment $twig,
        Security $security,
        AppointmentRepository $appointmentRepository,
        DoctorRepository $doctorRepository,
        UserRepository $userRepository,
        StatisticsService $statisticsService
    ) {
        $this->twig = $twig;
        $this->security = $security;
        $this->appointmentRepository = $appointmentRepository;
        $this->doctorRepository = $doctorRepository;
        $this->userRepository = $userRepository;
        $this->statisticsService = $statisticsService;
    }

    public function exportAppointmentsToPDF(\DateTime $start, \DateTime $end, ?Doctor $doctor = null): string
    {
        $this->validateAdminAccess();

        $appointments = $this->appointmentRepository->findByDateRange($start, $end, $doctor);
        
        $html = $this->twig->render('exports/appointments_pdf.html.twig', [
            'appointments' => $appointments,
            'start' => $start,
            'end' => $end,
            'doctor' => $doctor,
            'generatedAt' => new \DateTime(),
        ]);

        $dompdf = new Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function exportAppointmentsToExcel(\DateTime $start, \DateTime $end, ?Doctor $doctor = null): string
    {
        $this->validateAdminAccess();

        $appointments = $this->appointmentRepository->findByDateRange($start, $end, $doctor);
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $sheet->setCellValue('A1', 'Date');
        $sheet->setCellValue('B1', 'Heure');
        $sheet->setCellValue('C1', 'Patient');
        $sheet->setCellValue('D1', 'Médecin');
        $sheet->setCellValue('E1', 'Service');
        $sheet->setCellValue('F1', 'Statut');
        $sheet->setCellValue('G1', 'Prix');

        // Add data
        $row = 2;
        foreach ($appointments as $appointment) {
            $sheet->setCellValue('A' . $row, $appointment->getDateTime()->format('d/m/Y'));
            $sheet->setCellValue('B' . $row, $appointment->getDateTime()->format('H:i'));
            $sheet->setCellValue('C' . $row, $appointment->getPatient()->getFullName());
            $sheet->setCellValue('D' . $row, $appointment->getDoctor()->getFullName());
            $sheet->setCellValue('E' . $row, $appointment->getService()->getName());
            $sheet->setCellValue('F' . $row, $this->translateStatus($appointment->getStatus()));
            $sheet->setCellValue('G' . $row, $appointment->getService()->getPrice());
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Create Excel file
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'export_');
        $writer->save($tempFile);

        return file_get_contents($tempFile);
    }

    public function exportAppointmentsToCSV(\DateTime $start, \DateTime $end, ?Doctor $doctor = null): string
    {
        $this->validateAdminAccess();

        $appointments = $this->appointmentRepository->findByDateRange($start, $end, $doctor);
        
        $handle = fopen('php://temp', 'r+');

        // Add UTF-8 BOM
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        // Add headers
        fputcsv($handle, [
            'Date',
            'Heure',
            'Patient',
            'Médecin',
            'Service',
            'Statut',
            'Prix',
        ]);

        // Add data
        foreach ($appointments as $appointment) {
            fputcsv($handle, [
                $appointment->getDateTime()->format('d/m/Y'),
                $appointment->getDateTime()->format('H:i'),
                $appointment->getPatient()->getFullName(),
                $appointment->getDoctor()->getFullName(),
                $appointment->getService()->getName(),
                $this->translateStatus($appointment->getStatus()),
                $appointment->getService()->getPrice(),
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    public function exportStatisticsReport(\DateTime $start, \DateTime $end): string
    {
        $this->validateAdminAccess();

        $stats = $this->statisticsService->getDashboardStatistics();
        
        $html = $this->twig->render('exports/statistics_pdf.html.twig', [
            'stats' => $stats,
            'start' => $start,
            'end' => $end,
            'generatedAt' => new \DateTime(),
        ]);

        $dompdf = new Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function exportDoctorSchedule(Doctor $doctor, \DateTime $start, \DateTime $end): string
    {
        $appointments = $this->appointmentRepository->findByDoctorBetweenDates($doctor, $start, $end);
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $sheet->setCellValue('A1', 'Date');
        $sheet->setCellValue('B1', 'Heure');
        $sheet->setCellValue('C1', 'Patient');
        $sheet->setCellValue('D1', 'Service');
        $sheet->setCellValue('E1', 'Durée');
        $sheet->setCellValue('F1', 'Statut');

        // Add data
        $row = 2;
        foreach ($appointments as $appointment) {
            $sheet->setCellValue('A' . $row, $appointment->getDateTime()->format('d/m/Y'));
            $sheet->setCellValue('B' . $row, $appointment->getDateTime()->format('H:i'));
            $sheet->setCellValue('C' . $row, $appointment->getPatient()->getFullName());
            $sheet->setCellValue('D' . $row, $appointment->getService()->getName());
            $sheet->setCellValue('E' . $row, $appointment->getDuration() . ' min');
            $sheet->setCellValue('F' . $row, $this->translateStatus($appointment->getStatus()));
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'schedule_');
        $writer->save($tempFile);

        return file_get_contents($tempFile);
    }

    public function exportPatientHistory(User $patient): string
    {
        $this->validatePatientAccess($patient);

        $appointments = $this->appointmentRepository->findByPatient($patient);
        
        $html = $this->twig->render('exports/patient_history_pdf.html.twig', [
            'patient' => $patient,
            'appointments' => $appointments,
            'generatedAt' => new \DateTime(),
        ]);

        $dompdf = new Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function translateStatus(string $status): string
    {
        $translations = [
            'scheduled' => 'Programmé',
            'completed' => 'Terminé',
            'cancelled' => 'Annulé',
            'pending' => 'En attente',
        ];

        return $translations[$status] ?? $status;
    }

    private function validateAdminAccess(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new BadRequestException('Vous n\'avez pas les droits nécessaires pour exporter ces données.');
        }
    }

    private function validatePatientAccess(User $patient): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN') && 
            !$this->security->isGranted('ROLE_DOCTOR') && 
            $this->security->getUser() !== $patient) {
            throw new BadRequestException('Vous n\'avez pas les droits nécessaires pour accéder à cet historique.');
        }
    }
}
