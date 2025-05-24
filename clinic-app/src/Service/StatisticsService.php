<?php

namespace App\Service;

use App\Repository\AppointmentRepository;
use App\Repository\DoctorRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;

class StatisticsService
{
    private $entityManager;
    private $appointmentRepository;
    private $doctorRepository;
    private $serviceRepository;
    private $userRepository;
    private $security;

    public function __construct(
        EntityManagerInterface $entityManager,
        AppointmentRepository $appointmentRepository,
        DoctorRepository $doctorRepository,
        ServiceRepository $serviceRepository,
        UserRepository $userRepository,
        Security $security
    ) {
        $this->entityManager = $entityManager;
        $this->appointmentRepository = $appointmentRepository;
        $this->doctorRepository = $doctorRepository;
        $this->serviceRepository = $serviceRepository;
        $this->userRepository = $userRepository;
        $this->security = $security;
    }

    public function getDashboardStatistics(): array
    {
        return [
            'general' => $this->getGeneralStatistics(),
            'appointments' => $this->getAppointmentStatistics(),
            'services' => $this->getServiceStatistics(),
            'doctors' => $this->getDoctorStatistics(),
            'patients' => $this->getPatientStatistics(),
        ];
    }

    public function getGeneralStatistics(): array
    {
        $now = new \DateTime();
        $monthStart = new \DateTime('first day of this month');
        $monthEnd = new \DateTime('last day of this month');

        return [
            'total_appointments' => $this->appointmentRepository->count([]),
            'total_doctors' => $this->doctorRepository->count([]),
            'total_patients' => $this->userRepository->count(['roles' => 'ROLE_USER']),
            'total_services' => $this->serviceRepository->count(['isActive' => true]),
            'monthly_revenue' => $this->calculateMonthlyRevenue($monthStart, $monthEnd),
            'appointment_completion_rate' => $this->calculateAppointmentCompletionRate(),
        ];
    }

    public function getAppointmentStatistics(): array
    {
        $now = new \DateTime();
        $weekStart = (new \DateTime())->modify('monday this week');
        $weekEnd = (new \DateTime())->modify('sunday this week');

        return [
            'today' => [
                'total' => $this->appointmentRepository->countTodayAppointments(),
                'completed' => $this->appointmentRepository->countTodayCompletedAppointments(),
                'cancelled' => $this->appointmentRepository->countTodayCancelledAppointments(),
            ],
            'this_week' => [
                'total' => $this->appointmentRepository->countAppointmentsBetweenDates($weekStart, $weekEnd),
                'by_day' => $this->appointmentRepository->getAppointmentsCountByDay($weekStart, $weekEnd),
                'by_status' => $this->appointmentRepository->getAppointmentsCountByStatus($weekStart, $weekEnd),
            ],
            'trends' => [
                'busiest_days' => $this->appointmentRepository->getBusiestDays(),
                'busiest_hours' => $this->appointmentRepository->getBusiestHours(),
                'popular_services' => $this->appointmentRepository->getMostRequestedServices(),
            ],
            'cancellation_rate' => $this->calculateCancellationRate(),
        ];
    }

    public function getServiceStatistics(): array
    {
        return [
            'most_requested' => $this->serviceRepository->findMostRequestedServices(5),
            'by_category' => $this->serviceRepository->getServiceCountByCategory(),
            'average_duration' => $this->serviceRepository->getAverageDuration(),
            'average_price' => $this->serviceRepository->getAveragePrice(),
            'revenue_by_service' => $this->calculateRevenueByService(),
        ];
    }

    public function getDoctorStatistics(): array
    {
        return [
            'most_active' => $this->doctorRepository->findMostActiveDoctor(5),
            'by_speciality' => $this->doctorRepository->getDoctorCountBySpeciality(),
            'availability_rate' => $this->calculateDoctorAvailabilityRate(),
            'performance' => [
                'appointments_completed' => $this->appointmentRepository->getCompletedAppointmentsByDoctor(),
                'patient_satisfaction' => $this->calculateDoctorSatisfactionRate(),
                'average_duration' => $this->appointmentRepository->getAverageAppointmentDurationByDoctor(),
            ],
        ];
    }

    public function getPatientStatistics(): array
    {
        return [
            'new_patients' => $this->userRepository->countNewPatientsThisMonth(),
            'returning_patients' => $this->calculateReturningPatientsRate(),
            'demographics' => $this->getPatientDemographics(),
            'appointment_frequency' => $this->calculatePatientAppointmentFrequency(),
        ];
    }

    private function calculateMonthlyRevenue(\DateTime $start, \DateTime $end): float
    {
        return $this->appointmentRepository->calculateRevenueBetweenDates($start, $end);
    }

    private function calculateAppointmentCompletionRate(): float
    {
        $total = $this->appointmentRepository->count([]);
        if ($total === 0) {
            return 0;
        }

        $completed = $this->appointmentRepository->count(['status' => 'completed']);
        return ($completed / $total) * 100;
    }

    private function calculateCancellationRate(): float
    {
        $total = $this->appointmentRepository->count([]);
        if ($total === 0) {
            return 0;
        }

        $cancelled = $this->appointmentRepository->count(['status' => 'cancelled']);
        return ($cancelled / $total) * 100;
    }

    private function calculateRevenueByService(): array
    {
        $services = $this->serviceRepository->findAll();
        $revenue = [];

        foreach ($services as $service) {
            $revenue[$service->getName()] = $this->appointmentRepository->calculateRevenueByService($service);
        }

        return $revenue;
    }

    private function calculateDoctorAvailabilityRate(): array
    {
        $doctors = $this->doctorRepository->findAll();
        $rates = [];

        foreach ($doctors as $doctor) {
            $totalSlots = 0;
            $availableSlots = 0;

            foreach ($doctor->getSchedule() as $day => $hours) {
                $totalSlots += $this->calculateDailySlots($hours['start'], $hours['end']);
                $availableSlots += $this->appointmentRepository->countAvailableSlots($doctor, $day);
            }

            $rates[$doctor->getFullName()] = $totalSlots > 0 ? ($availableSlots / $totalSlots) * 100 : 0;
        }

        return $rates;
    }

    private function calculateDoctorSatisfactionRate(): array
    {
        $doctors = $this->doctorRepository->findAll();
        $rates = [];

        foreach ($doctors as $doctor) {
            $completedAppointments = $this->appointmentRepository->findCompletedAppointmentsForDoctor($doctor);
            $totalRating = 0;
            $ratedAppointments = 0;

            foreach ($completedAppointments as $appointment) {
                if ($appointment->getRating()) {
                    $totalRating += $appointment->getRating();
                    $ratedAppointments++;
                }
            }

            $rates[$doctor->getFullName()] = $ratedAppointments > 0 ? ($totalRating / $ratedAppointments) : 0;
        }

        return $rates;
    }

    private function calculateReturningPatientsRate(): float
    {
        $totalPatients = $this->userRepository->count(['roles' => 'ROLE_USER']);
        if ($totalPatients === 0) {
            return 0;
        }

        $returningPatients = $this->appointmentRepository->countPatientsWithMultipleAppointments();
        return ($returningPatients / $totalPatients) * 100;
    }

    private function getPatientDemographics(): array
    {
        // This would require additional user fields like age, gender, etc.
        return [
            'by_age' => $this->userRepository->getPatientCountByAgeGroup(),
            'by_gender' => $this->userRepository->getPatientCountByGender(),
            'by_location' => $this->userRepository->getPatientCountByLocation(),
        ];
    }

    private function calculatePatientAppointmentFrequency(): array
    {
        return [
            'average_visits' => $this->appointmentRepository->getAverageVisitsPerPatient(),
            'frequency_distribution' => $this->appointmentRepository->getAppointmentFrequencyDistribution(),
        ];
    }

    private function calculateDailySlots(string $start, string $end): int
    {
        $startTime = strtotime($start);
        $endTime = strtotime($end);
        $duration = 30 * 60; // 30 minutes in seconds

        return ($endTime - $startTime) / $duration;
    }
}
