<?php

namespace App\Repository;

use App\Entity\Appointment;
use App\Entity\Doctor;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTime;

/**
 * @extends ServiceEntityRepository<Appointment>
 *
 * @method Appointment|null find($id, $lockMode = null, $lockVersion = null)
 * @method Appointment|null findOneBy(array $criteria, array $orderBy = null)
 * @method Appointment[]    findAll()
 * @method Appointment[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AppointmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appointment::class);
    }

    public function save(Appointment $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Appointment $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find upcoming appointments for a patient
     */
    public function findUpcomingAppointmentsForPatient(User $patient): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.patient = :patient')
            ->andWhere('a.dateTime > :now')
            ->andWhere('a.status IN (:statuses)')
            ->setParameter('patient', $patient)
            ->setParameter('now', new DateTime())
            ->setParameter('statuses', ['scheduled', 'rescheduled'])
            ->orderBy('a.dateTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find upcoming appointments for a doctor
     */
    public function findUpcomingAppointmentsForDoctor(Doctor $doctor): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.doctor = :doctor')
            ->andWhere('a.dateTime > :now')
            ->andWhere('a.status IN (:statuses)')
            ->setParameter('doctor', $doctor)
            ->setParameter('now', new DateTime())
            ->setParameter('statuses', ['scheduled', 'rescheduled'])
            ->orderBy('a.dateTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find appointments that need reminders
     */
    public function findAppointmentsNeedingReminders(int $hoursBeforeAppointment): array
    {
        $now = new DateTime();
        $reminderTime = (clone $now)->modify("+{$hoursBeforeAppointment} hours");

        return $this->createQueryBuilder('a')
            ->andWhere('a.dateTime BETWEEN :now AND :reminderTime')
            ->andWhere('a.status IN (:statuses)')
            ->andWhere('a.reminderSentAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('reminderTime', $reminderTime)
            ->setParameter('statuses', ['scheduled', 'rescheduled'])
            ->orderBy('a.dateTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a time slot is available for a doctor
     */
    public function isTimeSlotAvailable(Doctor $doctor, DateTime $startTime, int $duration): bool
    {
        $endTime = (clone $startTime)->modify("+{$duration} minutes");

        $conflictingAppointments = $this->createQueryBuilder('a')
            ->andWhere('a.doctor = :doctor')
            ->andWhere('a.status IN (:statuses)')
            ->andWhere(
                '(a.dateTime BETWEEN :startTime AND :endTime) OR 
                (DATE_ADD(a.dateTime, a.duration, \'minute\') BETWEEN :startTime AND :endTime)'
            )
            ->setParameter('doctor', $doctor)
            ->setParameter('statuses', ['scheduled', 'rescheduled'])
            ->setParameter('startTime', $startTime)
            ->setParameter('endTime', $endTime)
            ->getQuery()
            ->getResult();

        return count($conflictingAppointments) === 0;
    }

    /**
     * Find appointments by date range
     */
    public function findByDateRange(DateTime $startDate, DateTime $endDate): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.dateTime BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('a.dateTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get appointment statistics
     */
    public function getStatistics(DateTime $startDate, DateTime $endDate): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id) as total')
            ->addSelect('a.status')
            ->andWhere('a.dateTime BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->groupBy('a.status');

        $results = $qb->getQuery()->getResult();

        $stats = [
            'total' => 0,
            'scheduled' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'noShow' => 0
        ];

        foreach ($results as $result) {
            $stats[$result['status']] = $result['total'];
            $stats['total'] += $result['total'];
        }

        return $stats;
    }
}
