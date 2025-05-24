<?php

namespace App\Repository;

use App\Entity\Service;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Service>
 *
 * @method Service|null find($id, $lockMode = null, $lockVersion = null)
 * @method Service|null findOneBy(array $criteria, array $orderBy = null)
 * @method Service[]    findAll()
 * @method Service[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Service::class);
    }

    public function save(Service $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Service $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find all active services
     */
    public function findActiveServices(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find services by speciality
     */
    public function findBySpeciality(string $speciality): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.isActive = :active')
            ->andWhere('JSON_CONTAINS(s.requiredSpecialities, :speciality) = 1')
            ->setParameter('active', true)
            ->setParameter('speciality', json_encode($speciality))
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find services within a price range
     */
    public function findByPriceRange(float $minPrice, float $maxPrice): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.isActive = :active')
            ->andWhere('s.price BETWEEN :minPrice AND :maxPrice')
            ->setParameter('active', true)
            ->setParameter('minPrice', $minPrice)
            ->setParameter('maxPrice', $maxPrice)
            ->orderBy('s.price', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find services by duration (in minutes)
     */
    public function findByDuration(int $maxDuration): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.isActive = :active')
            ->andWhere('s.duration <= :maxDuration')
            ->setParameter('active', true)
            ->setParameter('maxDuration', $maxDuration)
            ->orderBy('s.duration', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search services by name or description
     */
    public function searchServices(string $query): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.isActive = :active')
            ->andWhere(
                's.name LIKE :query OR s.description LIKE :query'
            )
            ->setParameter('active', true)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get popular services based on appointment count
     */
    public function findPopularServices(int $limit = 5): array
    {
        return $this->createQueryBuilder('s')
            ->select('s', 'COUNT(a.id) as appointmentCount')
            ->leftJoin('App\Entity\Appointment', 'a', 'WITH', 'a.service = s')
            ->andWhere('s.isActive = :active')
            ->groupBy('s.id')
            ->setParameter('active', true)
            ->orderBy('appointmentCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
