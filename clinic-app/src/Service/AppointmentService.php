<?php

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\Doctor;
use App\Entity\User;
use App\Entity\Service as MedicalService;
use App\Repository\AppointmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class AppointmentService
{
    private $entityManager;
    private $appointmentRepository;
    private $security;
    private $notificationService;

    public function __construct(
        EntityManagerInterface $entityManager,
        AppointmentRepository $appointmentRepository,
        Security $security,
        NotificationService $notificationService
    ) {
        $this->entityManager = $entityManager;
        $this->appointmentRepository = $appointmentRepository;
        $this->security = $security;
        $this->notificationService = $notificationService;
    }

    public function createAppointment(
        \DateTime $dateTime,
        Doctor $doctor,
        MedicalService $service,
        ?string $reason = null
    ): Appointment {
        $patient = $this->security->getUser();
        
        if (!$patient instanceof User) {
            throw new BadRequestException('User must be authenticated to create an appointment');
        }

        // Validate appointment time
        $this->validateAppointmentTime($dateTime, $doctor);

        // Create new appointment
        $appointment = new Appointment();
        $appointment->setDateTime($dateTime)
                   ->setDoctor($doctor)
                   ->setPatient($patient)
                   ->setService($service)
                   ->setReason($reason)
                   ->setStatus('pending');

        // Save appointment
        $this->entityManager->persist($appointment);
        $this->entityManager->flush();

        // Send notifications
        $this->notificationService->sendAppointmentConfirmation($appointment);

        return $appointment;
    }

    public function confirmAppointment(Appointment $appointment): void
    {
        $this->validateUserCanModifyAppointment($appointment);

        $appointment->setStatus('scheduled');
        $this->entityManager->flush();

        $this->notificationService->sendAppointmentConfirmation($appointment);
    }

    public function cancelAppointment(Appointment $appointment, ?string $reason = null): void
    {
        $this->validateUserCanModifyAppointment($appointment);

        $appointment->setStatus('cancelled')
                   ->setCancellationReason($reason);
        $this->entityManager->flush();

        $this->notificationService->sendAppointmentCancellation($appointment, $reason);
    }

    public function rescheduleAppointment(Appointment $appointment, \DateTime $newDateTime): void
    {
        $this->validateUserCanModifyAppointment($appointment);
        $this->validateAppointmentTime($newDateTime, $appointment->getDoctor());

        $oldDateTime = $appointment->getDateTime();
        $appointment->setDateTime($newDateTime);
        $this->entityManager->flush();

        // Send notifications about rescheduling
        $this->notificationService->sendAppointmentRescheduled($appointment, $oldDateTime);
    }

    public function completeAppointment(Appointment $appointment, ?string $notes = null): void
    {
        if (!$this->security->isGranted('ROLE_DOCTOR')) {
            throw new BadRequestException('Only doctors can mark appointments as completed');
        }

        $appointment->setStatus('completed')
                   ->setNotes($notes);
        $this->entityManager->flush();
    }

    private function validateAppointmentTime(\DateTime $dateTime, Doctor $doctor): void
    {
        // Check if date is in the future
        if ($dateTime <= new \DateTime()) {
            throw new BadRequestException('Appointment date must be in the future');
        }

        // Check if date is within working hours
        $dayOfWeek = strtolower($dateTime->format('l'));
        $time = $dateTime->format('H:i');
        
        if (!$this->isWithinWorkingHours($dayOfWeek, $time, $doctor)) {
            throw new BadRequestException('Appointment time must be within doctor\'s working hours');
        }

        // Check for conflicts
        if ($this->hasTimeConflict($dateTime, $doctor)) {
            throw new BadRequestException('Selected time slot is not available');
        }
    }

    private function isWithinWorkingHours(string $dayOfWeek, string $time, Doctor $doctor): bool
    {
        $schedule = $doctor->getSchedule()[$dayOfWeek] ?? null;
        
        if (!$schedule) {
            return false;
        }

        return $time >= $schedule['start'] && $time <= $schedule['end'];
    }

    private function hasTimeConflict(\DateTime $dateTime, Doctor $doctor): bool
    {
        $existingAppointments = $this->appointmentRepository->findConflictingAppointments(
            $doctor,
            $dateTime,
            30 // Default appointment duration in minutes
        );

        return count($existingAppointments) > 0;
    }

    private function validateUserCanModifyAppointment(Appointment $appointment): void
    {
        $user = $this->security->getUser();

        if (!$user) {
            throw new BadRequestException('User must be authenticated');
        }

        // Allow doctors to modify their own appointments
        if ($this->security->isGranted('ROLE_DOCTOR') && $appointment->getDoctor() === $user) {
            return;
        }

        // Allow patients to modify their own appointments
        if ($appointment->getPatient() === $user) {
            return;
        }

        throw new BadRequestException('You are not authorized to modify this appointment');
    }

    public function getAvailableTimeSlots(Doctor $doctor, \DateTime $date): array
    {
        $dayOfWeek = strtolower($date->format('l'));
        $schedule = $doctor->getSchedule()[$dayOfWeek] ?? null;

        if (!$schedule) {
            return [];
        }

        $slots = [];
        $start = new \DateTime($date->format('Y-m-d') . ' ' . $schedule['start']);
        $end = new \DateTime($date->format('Y-m-d') . ' ' . $schedule['end']);
        $interval = new \DateInterval('PT30M'); // 30-minute slots

        $current = clone $start;
        while ($current < $end) {
            if (!$this->hasTimeConflict($current, $doctor)) {
                $slots[] = clone $current;
            }
            $current->add($interval);
        }

        return $slots;
    }

    public function getDoctorAvailability(Doctor $doctor, \DateTime $startDate, \DateTime $endDate): array
    {
        $availability = [];
        $current = clone $startDate;

        while ($current <= $endDate) {
            $dayOfWeek = strtolower($current->format('l'));
            $schedule = $doctor->getSchedule()[$dayOfWeek] ?? null;

            if ($schedule) {
                $availability[$current->format('Y-m-d')] = [
                    'available' => true,
                    'hours' => $schedule,
                    'slots' => $this->getAvailableTimeSlots($doctor, clone $current),
                ];
            } else {
                $availability[$current->format('Y-m-d')] = [
                    'available' => false,
                ];
            }

            $current->modify('+1 day');
        }

        return $availability;
    }
}
