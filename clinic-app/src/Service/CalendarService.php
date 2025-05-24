<?php

namespace App\Service;

use App\Entity\Doctor;
use App\Entity\Appointment;
use App\Entity\TimeSlot;
use App\Entity\WorkingHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Psr\Log\LoggerInterface;

class CalendarService
{
    private const SLOT_DURATION = 30; // minutes
    private const MIN_ADVANCE_BOOKING = 24; // hours
    private const MAX_ADVANCE_BOOKING = 60; // days
    private const WORKING_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
    private const WORKING_HOURS = [
        'start' => '09:00',
        'end' => '18:00',
        'lunch_start' => '12:00',
        'lunch_end' => '13:00',
    ];

    private $entityManager;
    private $security;
    private $logger;
    private $settingsService;
    private $notificationService;

    public function __construct(
        EntityManagerInterface $entityManager,
        Security $security,
        LoggerInterface $logger,
        SettingsService $settingsService,
        NotificationService $notificationService
    ) {
        $this->entityManager = $entityManager;
        $this->security = $security;
        $this->logger = $logger;
        $this->settingsService = $settingsService;
        $this->notificationService = $notificationService;
    }

    public function getAvailableSlots(
        Doctor $doctor,
        \DateTime $startDate,
        \DateTime $endDate,
        int $duration = self::SLOT_DURATION
    ): array {
        $this->validateDateRange($startDate, $endDate);

        try {
            $workingHours = $this->getWorkingHours($doctor);
            $existingAppointments = $this->getExistingAppointments($doctor, $startDate, $endDate);
            $unavailabilities = $this->getUnavailabilities($doctor, $startDate, $endDate);

            $slots = [];
            $currentDate = clone $startDate;

            while ($currentDate <= $endDate) {
                if ($this->isWorkingDay($currentDate, $workingHours)) {
                    $daySlots = $this->generateDaySlots($currentDate, $workingHours, $duration);
                    $availableSlots = $this->filterAvailableSlots(
                        $daySlots,
                        $existingAppointments,
                        $unavailabilities,
                        $duration
                    );

                    if (!empty($availableSlots)) {
                        $slots[$currentDate->format('Y-m-d')] = $availableSlots;
                    }
                }
                $currentDate->modify('+1 day');
            }

            return $slots;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get available slots', [
                'doctor_id' => $doctor->getId(),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getDoctorSchedule(Doctor $doctor, \DateTime $date): array
    {
        $startDate = (clone $date)->setTime(0, 0);
        $endDate = (clone $date)->setTime(23, 59, 59);

        return [
            'working_hours' => $this->getWorkingHours($doctor),
            'appointments' => $this->getExistingAppointments($doctor, $startDate, $endDate),
            'unavailabilities' => $this->getUnavailabilities($doctor, $startDate, $endDate),
        ];
    }

    public function isSlotAvailable(Doctor $doctor, \DateTime $startTime, int $duration = self::SLOT_DURATION): bool
    {
        try {
            $endTime = (clone $startTime)->modify("+{$duration} minutes");
            
            // Check if within working hours
            if (!$this->isWithinWorkingHours($doctor, $startTime, $endTime)) {
                return false;
            }

            // Check for existing appointments
            $existingAppointments = $this->getExistingAppointments($doctor, $startTime, $endTime);
            if ($this->hasOverlappingAppointments($startTime, $endTime, $existingAppointments)) {
                return false;
            }

            // Check for unavailabilities
            $unavailabilities = $this->getUnavailabilities($doctor, $startTime, $endTime);
            if ($this->hasOverlappingUnavailabilities($startTime, $endTime, $unavailabilities)) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to check slot availability', [
                'doctor_id' => $doctor->getId(),
                'start_time' => $startTime->format('Y-m-d H:i'),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function setWorkingHours(Doctor $doctor, array $workingHours): void
    {
        $this->validateWorkingHours($workingHours);

        try {
            $existingHours = $this->entityManager->getRepository(WorkingHours::class)
                ->findBy(['doctor' => $doctor]);

            // Remove existing working hours
            foreach ($existingHours as $hours) {
                $this->entityManager->remove($hours);
            }

            // Create new working hours
            foreach ($workingHours as $day => $hours) {
                $workingHour = new WorkingHours();
                $workingHour->setDoctor($doctor)
                           ->setDayOfWeek($day)
                           ->setStartTime(new \DateTime($hours['start']))
                           ->setEndTime(new \DateTime($hours['end']))
                           ->setLunchStart(new \DateTime($hours['lunch_start']))
                           ->setLunchEnd(new \DateTime($hours['lunch_end']));

                $this->entityManager->persist($workingHour);
            }

            $this->entityManager->flush();

            $this->logger->info('Working hours updated', [
                'doctor_id' => $doctor->getId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to set working hours', [
                'doctor_id' => $doctor->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function addUnavailability(Doctor $doctor, \DateTime $startTime, \DateTime $endTime, string $reason = null): void
    {
        try {
            $unavailability = new TimeSlot();
            $unavailability->setDoctor($doctor)
                         ->setStartTime($startTime)
                         ->setEndTime($endTime)
                         ->setType('unavailable')
                         ->setReason($reason);

            $this->entityManager->persist($unavailability);
            $this->entityManager->flush();

            // Notify affected patients
            $this->notifyAffectedPatients($doctor, $startTime, $endTime);

            $this->logger->info('Unavailability added', [
                'doctor_id' => $doctor->getId(),
                'start_time' => $startTime->format('Y-m-d H:i'),
                'end_time' => $endTime->format('Y-m-d H:i'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to add unavailability', [
                'doctor_id' => $doctor->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function generateDaySlots(\DateTime $date, array $workingHours, int $duration): array
    {
        $slots = [];
        $dayOfWeek = strtolower($date->format('l'));
        
        if (!isset($workingHours[$dayOfWeek])) {
            return $slots;
        }

        $startTime = new \DateTime($date->format('Y-m-d') . ' ' . $workingHours[$dayOfWeek]['start']);
        $endTime = new \DateTime($date->format('Y-m-d') . ' ' . $workingHours[$dayOfWeek]['end']);
        $lunchStart = new \DateTime($date->format('Y-m-d') . ' ' . $workingHours[$dayOfWeek]['lunch_start']);
        $lunchEnd = new \DateTime($date->format('Y-m-d') . ' ' . $workingHours[$dayOfWeek]['lunch_end']);

        $current = clone $startTime;
        while ($current < $endTime) {
            $slotEnd = (clone $current)->modify("+{$duration} minutes");
            
            // Skip lunch break
            if (!($current >= $lunchStart && $current < $lunchEnd)) {
                $slots[] = [
                    'start' => clone $current,
                    'end' => clone $slotEnd,
                ];
            }
            
            $current = $slotEnd;
        }

        return $slots;
    }

    private function filterAvailableSlots(array $slots, array $appointments, array $unavailabilities, int $duration): array
    {
        return array_filter($slots, function($slot) use ($appointments, $unavailabilities, $duration) {
            // Check if slot is in the past
            if ($slot['start'] < new \DateTime()) {
                return false;
            }

            // Check for overlapping appointments
            if ($this->hasOverlappingAppointments($slot['start'], $slot['end'], $appointments)) {
                return false;
            }

            // Check for overlapping unavailabilities
            if ($this->hasOverlappingUnavailabilities($slot['start'], $slot['end'], $unavailabilities)) {
                return false;
            }

            return true;
        });
    }

    private function hasOverlappingAppointments(\DateTime $start, \DateTime $end, array $appointments): bool
    {
        foreach ($appointments as $appointment) {
            if ($this->isOverlapping($start, $end, $appointment->getStartTime(), $appointment->getEndTime())) {
                return true;
            }
        }
        return false;
    }

    private function hasOverlappingUnavailabilities(\DateTime $start, \DateTime $end, array $unavailabilities): bool
    {
        foreach ($unavailabilities as $unavailability) {
            if ($this->isOverlapping($start, $end, $unavailability->getStartTime(), $unavailability->getEndTime())) {
                return true;
            }
        }
        return false;
    }

    private function isOverlapping(\DateTime $start1, \DateTime $end1, \DateTime $start2, \DateTime $end2): bool
    {
        return $start1 < $end2 && $end1 > $start2;
    }

    private function getWorkingHours(Doctor $doctor): array
    {
        $workingHours = $this->entityManager->getRepository(WorkingHours::class)
            ->findBy(['doctor' => $doctor]);

        $hours = [];
        foreach ($workingHours as $workingHour) {
            $hours[$workingHour->getDayOfWeek()] = [
                'start' => $workingHour->getStartTime()->format('H:i'),
                'end' => $workingHour->getEndTime()->format('H:i'),
                'lunch_start' => $workingHour->getLunchStart()->format('H:i'),
                'lunch_end' => $workingHour->getLunchEnd()->format('H:i'),
            ];
        }

        return $hours;
    }

    private function getExistingAppointments(Doctor $doctor, \DateTime $start, \DateTime $end): array
    {
        return $this->entityManager->getRepository(Appointment::class)
            ->findByDoctorAndDateRange($doctor, $start, $end);
    }

    private function getUnavailabilities(Doctor $doctor, \DateTime $start, \DateTime $end): array
    {
        return $this->entityManager->getRepository(TimeSlot::class)
            ->findByDoctorAndDateRange($doctor, $start, $end, 'unavailable');
    }

    private function isWorkingDay(\DateTime $date, array $workingHours): bool
    {
        $dayOfWeek = strtolower($date->format('l'));
        return isset($workingHours[$dayOfWeek]);
    }

    private function isWithinWorkingHours(Doctor $doctor, \DateTime $start, \DateTime $end): bool
    {
        $workingHours = $this->getWorkingHours($doctor);
        $dayOfWeek = strtolower($start->format('l'));

        if (!isset($workingHours[$dayOfWeek])) {
            return false;
        }

        $dayStart = new \DateTime($start->format('Y-m-d') . ' ' . $workingHours[$dayOfWeek]['start']);
        $dayEnd = new \DateTime($start->format('Y-m-d') . ' ' . $workingHours[$dayOfWeek]['end']);
        $lunchStart = new \DateTime($start->format('Y-m-d') . ' ' . $workingHours[$dayOfWeek]['lunch_start']);
        $lunchEnd = new \DateTime($start->format('Y-m-d') . ' ' . $workingHours[$dayOfWeek]['lunch_end']);

        return $start >= $dayStart && $end <= $dayEnd && !($start < $lunchEnd && $end > $lunchStart);
    }

    private function validateDateRange(\DateTime $start, \DateTime $end): void
    {
        $now = new \DateTime();
        $minDate = (clone $now)->modify('+' . self::MIN_ADVANCE_BOOKING . ' hours');
        $maxDate = (clone $now)->modify('+' . self::MAX_ADVANCE_BOOKING . ' days');

        if ($start < $minDate) {
            throw new BadRequestException(sprintf(
                'Appointments must be booked at least %d hours in advance',
                self::MIN_ADVANCE_BOOKING
            ));
        }

        if ($end > $maxDate) {
            throw new BadRequestException(sprintf(
                'Appointments cannot be booked more than %d days in advance',
                self::MAX_ADVANCE_BOOKING
            ));
        }

        if ($start > $end) {
            throw new BadRequestException('Start date must be before end date');
        }
    }

    private function validateWorkingHours(array $workingHours): void
    {
        foreach ($workingHours as $day => $hours) {
            if (!in_array($day, self::WORKING_DAYS)) {
                throw new BadRequestException('Invalid working day: ' . $day);
            }

            if (!isset($hours['start'], $hours['end'], $hours['lunch_start'], $hours['lunch_end'])) {
                throw new BadRequestException('Missing required working hours for ' . $day);
            }

            // Validate time format and ranges
            foreach (['start', 'end', 'lunch_start', 'lunch_end'] as $key) {
                if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hours[$key])) {
                    throw new BadRequestException('Invalid time format for ' . $day . ' ' . $key);
                }
            }
        }
    }

    private function notifyAffectedPatients(Doctor $doctor, \DateTime $start, \DateTime $end): void
    {
        $appointments = $this->getExistingAppointments($doctor, $start, $end);

        foreach ($appointments as $appointment) {
            $this->notificationService->sendAppointmentCancellation(
                $appointment,
                'Doctor unavailable during this time'
            );
        }
    }
}
