<?php

namespace App\Repository;

use App\Entity\Doctor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTime;

/**
 * @extends ServiceEntityRepository<Doctor>
 *
 * @method Doctor|null find($id, $lockMode = null, $lockVersion = null)
 * @method Doctor|null findOneBy(array $criteria, array $orderBy = null)
 * @method Doctor[]    findAll()
 * @method Doctor[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DoctorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Doctor::class);
    }

    public function save(Doctor $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Doctor $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find available doctors by speciality and date
     */
    public function findAvailableDoctorsBySpeciality(string $speciality, DateTime $date): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.speciality = :speciality')
            ->andWhere('d.isAvailable = :available')
            ->setParameter('speciality', $speciality)
            ->setParameter('available', true)
            ->orderBy('d.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find doctors with the least appointments for a given date
     */
    public function findLeastBusyDoctors(DateTime $date): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.appointments', 'a')
            ->andWhere('d.isAvailable = :available')
            ->andWhere('a.dateTime IS NULL OR DATE(a.dateTime) != :date')
            ->setParameter('available', true)
            ->setParameter('date', $date->format('Y-m-d'))
            ->orderBy('d.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find doctors by multiple specialities
     */
    public function findBySpecialities(array $specialities): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.speciality IN (:specialities)')
            ->andWhere('d.isAvailable = :available')
            ->setParameter('specialities', $specialities)
            ->setParameter('available', true)
            ->orderBy('d.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find doctors available during specific working hours
     */
    public function findByWorkingHours(string $dayOfWeek, string $timeSlot): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.isAvailable = :available')
            ->andWhere('JSON_CONTAINS(d.workingHours, :schedule) = 1')
            ->setParameter('available', true)
            ->setParameter('schedule', json_encode([$dayOfWeek => $timeSlot]))
            ->orderBy('d.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search doctors by name or speciality
     */
    public function searchDoctors(string $query): array
    {
        $qb = $this->createQueryBuilder('d');
        
        return $qb->where(
            $qb->expr()->orX(
                $qb->expr()->like('LOWER(d.firstName)', ':query'),
                $qb->expr()->like('LOWER(d.lastName)', ':query'),
                $qb->expr()->like('LOWER(d.speciality)', ':query')
            )
        )
            ->setParameter('query', '%' . strtolower($query) . '%')
            ->orderBy('d.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
