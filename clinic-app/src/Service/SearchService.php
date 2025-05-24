<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Doctor;
use App\Entity\Appointment;
use App\Entity\Service;
use App\Entity\MedicalRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Psr\Log\LoggerInterface;

class SearchService
{
    private const SEARCHABLE_ENTITIES = [
        'users' => User::class,
        'doctors' => Doctor::class,
        'appointments' => Appointment::class,
        'services' => Service::class,
        'medical_records' => MedicalRecord::class,
    ];

    private const MAX_RESULTS = 100;
    private const MIN_QUERY_LENGTH = 2;

    private $entityManager;
    private $security;
    private $logger;
    private $securityService;

    public function __construct(
        EntityManagerInterface $entityManager,
        Security $security,
        LoggerInterface $logger,
        SecurityService $securityService
    ) {
        $this->entityManager = $entityManager;
        $this->security = $security;
        $this->logger = $logger;
        $this->securityService = $securityService;
    }

    public function search(string $type, string $query, array $filters = [], array $options = []): array
    {
        $this->validateSearchRequest($type, $query);

        try {
            $qb = $this->entityManager->createQueryBuilder();
            $entityClass = self::SEARCHABLE_ENTITIES[$type];
            $alias = 'e';

            $qb->select($alias)
               ->from($entityClass, $alias);

            // Apply search criteria based on entity type
            $this->applySearchCriteria($qb, $type, $alias, $query);

            // Apply filters
            $this->applyFilters($qb, $type, $alias, $filters);

            // Apply security constraints
            $this->applySecurityConstraints($qb, $type, $alias);

            // Apply sorting
            $this->applySorting($qb, $type, $alias, $options['sort'] ?? []);

            // Apply pagination
            $limit = min($options['limit'] ?? self::MAX_RESULTS, self::MAX_RESULTS);
            $offset = ($options['page'] ?? 0) * $limit;
            
            $qb->setMaxResults($limit)
               ->setFirstResult($offset);

            $results = $qb->getQuery()->getResult();

            $this->logger->info('Search performed', [
                'type' => $type,
                'query' => $query,
                'filters' => $filters,
                'results_count' => count($results),
            ]);

            return [
                'results' => $results,
                'total' => $this->getTotalCount($qb),
                'page' => $options['page'] ?? 0,
                'limit' => $limit,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Search failed', [
                'type' => $type,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function searchGlobal(string $query, array $options = []): array
    {
        $this->validateQuery($query);

        $results = [];
        foreach (self::SEARCHABLE_ENTITIES as $type => $entityClass) {
            try {
                $typeResults = $this->search($type, $query, [], array_merge($options, [
                    'limit' => 10, // Limit results per entity type for global search
                ]));
                if (!empty($typeResults['results'])) {
                    $results[$type] = $typeResults;
                }
            } catch (\Exception $e) {
                $this->logger->warning("Failed to search {$type}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    public function suggest(string $type, string $query, int $limit = 5): array
    {
        $this->validateSearchRequest($type, $query);

        try {
            $qb = $this->entityManager->createQueryBuilder();
            $entityClass = self::SEARCHABLE_ENTITIES[$type];
            $alias = 'e';

            $qb->select($alias)
               ->from($entityClass, $alias);

            $this->applySuggestionCriteria($qb, $type, $alias, $query);
            $this->applySecurityConstraints($qb, $type, $alias);

            $qb->setMaxResults($limit);

            return $qb->getQuery()->getResult();
        } catch (\Exception $e) {
            $this->logger->error('Suggestion failed', [
                'type' => $type,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function applySearchCriteria($qb, string $type, string $alias, string $query): void
    {
        $conditions = [];
        
        switch ($type) {
            case 'users':
                $conditions[] = $qb->expr()->like("CONCAT({$alias}.firstName, ' ', {$alias}.lastName)", ':query');
                $conditions[] = $qb->expr()->like("{$alias}.email", ':query');
                $conditions[] = $qb->expr()->like("{$alias}.phoneNumber", ':query');
                break;

            case 'doctors':
                $conditions[] = $qb->expr()->like("CONCAT({$alias}.firstName, ' ', {$alias}.lastName)", ':query');
                $conditions[] = $qb->expr()->like("{$alias}.specialization", ':query');
                break;

            case 'appointments':
                $conditions[] = $qb->expr()->like("{$alias}.notes", ':query');
                $qb->leftJoin("{$alias}.patient", 'p')
                   ->leftJoin("{$alias}.doctor", 'd');
                $conditions[] = $qb->expr()->like("CONCAT(p.firstName, ' ', p.lastName)", ':query');
                $conditions[] = $qb->expr()->like("CONCAT(d.firstName, ' ', d.lastName)", ':query');
                break;

            case 'services':
                $conditions[] = $qb->expr()->like("{$alias}.name", ':query');
                $conditions[] = $qb->expr()->like("{$alias}.description", ':query');
                break;

            case 'medical_records':
                $conditions[] = $qb->expr()->like("{$alias}.title", ':query');
                $conditions[] = $qb->expr()->like("{$alias}.description", ':query');
                break;
        }

        if (!empty($conditions)) {
            $qb->andWhere($qb->expr()->orX(...$conditions))
               ->setParameter('query', '%' . $query . '%');
        }
    }

    private function applySuggestionCriteria($qb, string $type, string $alias, string $query): void
    {
        switch ($type) {
            case 'users':
            case 'doctors':
                $qb->andWhere($qb->expr()->like("CONCAT({$alias}.firstName, ' ', {$alias}.lastName)", ':query'))
                   ->setParameter('query', $query . '%');
                break;

            case 'services':
                $qb->andWhere($qb->expr()->like("{$alias}.name", ':query'))
                   ->setParameter('query', $query . '%');
                break;

            default:
                $this->applySearchCriteria($qb, $type, $alias, $query);
        }
    }

    private function applyFilters($qb, string $type, string $alias, array $filters): void
    {
        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            switch ($type) {
                case 'appointments':
                    if ($field === 'date_from') {
                        $qb->andWhere("{$alias}.dateTime >= :dateFrom")
                           ->setParameter('dateFrom', new \DateTime($value));
                    }
                    if ($field === 'date_to') {
                        $qb->andWhere("{$alias}.dateTime <= :dateTo")
                           ->setParameter('dateTo', new \DateTime($value));
                    }
                    if ($field === 'status') {
                        $qb->andWhere("{$alias}.status = :status")
                           ->setParameter('status', $value);
                    }
                    break;

                case 'doctors':
                    if ($field === 'specialization') {
                        $qb->andWhere("{$alias}.specialization = :specialization")
                           ->setParameter('specialization', $value);
                    }
                    if ($field === 'available') {
                        $qb->andWhere("{$alias}.available = :available")
                           ->setParameter('available', $value);
                    }
                    break;

                case 'medical_records':
                    if ($field === 'type') {
                        $qb->andWhere("{$alias}.type = :type")
                           ->setParameter('type', $value);
                    }
                    if ($field === 'date_from') {
                        $qb->andWhere("{$alias}.createdAt >= :dateFrom")
                           ->setParameter('dateFrom', new \DateTime($value));
                    }
                    break;
            }
        }
    }

    private function applySecurityConstraints($qb, string $type, string $alias): void
    {
        $user = $this->security->getUser();

        if (!$user) {
            throw new BadRequestException('User must be authenticated to perform search');
        }

        if (!$this->security->isGranted('ROLE_ADMIN')) {
            switch ($type) {
                case 'medical_records':
                    if ($user instanceof Doctor) {
                        $qb->leftJoin("{$alias}.patient", 'p')
                           ->leftJoin('p.appointments', 'a')
                           ->andWhere('a.doctor = :doctor')
                           ->setParameter('doctor', $user);
                    } else {
                        $qb->andWhere("{$alias}.patient = :patient")
                           ->setParameter('patient', $user);
                    }
                    break;

                case 'appointments':
                    if ($user instanceof Doctor) {
                        $qb->andWhere("{$alias}.doctor = :doctor")
                           ->setParameter('doctor', $user);
                    } else {
                        $qb->andWhere("{$alias}.patient = :patient")
                           ->setParameter('patient', $user);
                    }
                    break;
            }
        }
    }

    private function applySorting($qb, string $type, string $alias, array $sort): void
    {
        if (empty($sort)) {
            // Default sorting
            switch ($type) {
                case 'appointments':
                    $qb->orderBy("{$alias}.dateTime", 'DESC');
                    break;
                case 'medical_records':
                    $qb->orderBy("{$alias}.createdAt", 'DESC');
                    break;
                default:
                    $qb->orderBy("{$alias}.id", 'DESC');
            }
            return;
        }

        foreach ($sort as $field => $direction) {
            $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
            $qb->addOrderBy("{$alias}.{$field}", $direction);
        }
    }

    private function getTotalCount($qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT ' . $countQb->getRootAliases()[0] . '.id)');
        $countQb->setMaxResults(null);
        $countQb->setFirstResult(null);
        
        return (int) $countQb->getQuery()->getSingleScalarResult();
    }

    private function validateSearchRequest(string $type, string $query): void
    {
        if (!isset(self::SEARCHABLE_ENTITIES[$type])) {
            throw new BadRequestException(sprintf(
                'Invalid search type. Allowed types: %s',
                implode(', ', array_keys(self::SEARCHABLE_ENTITIES))
            ));
        }

        $this->validateQuery($query);
    }

    private function validateQuery(string $query): void
    {
        if (strlen($query) < self::MIN_QUERY_LENGTH) {
            throw new BadRequestException(sprintf(
                'Search query must be at least %d characters long',
                self::MIN_QUERY_LENGTH
            ));
        }
    }
}
