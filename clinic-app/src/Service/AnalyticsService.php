<?php

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\Doctor;
use App\Entity\User;
use App\Entity\Service;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Psr\Log\LoggerInterface;

class AnalyticsService
{
    private const REPORT_TYPES = [
        'appointments',
        'revenue',
        'patients',
        'services',
        'doctors',
        'satisfaction',
    ];

    private $entityManager;
    private $security;
    private $logger;
    private $cacheService;

    public function __construct(
        EntityManagerInterface $entityManager,
        Security $security,
        LoggerInterface $logger,
        CacheService $cacheService
    ) {
        $this->entityManager = $entityManager;
        $this->security = $security;
        $this->logger = $logger;
        $this->cacheService = $cacheService;
    }

    public function getDashboardMetrics(): array
    {
        return $this->cacheService->get('dashboard_metrics', function () {
            return [
                'appointments' => $this->getAppointmentMetrics(),
                'revenue' => $this->getRevenueMetrics(),
                'patients' => $this->getPatientMetrics(),
                'services' => $this->getServiceMetrics(),
            ];
        }, 3600); // Cache for 1 hour
    }

    public function generateReport(string $type, \DateTime $start, \DateTime $end, array $filters = []): array
    {
        if (!in_array($type, self::REPORT_TYPES)) {
            throw new \InvalidArgumentException(sprintf('Invalid report type: %s', $type));
        }

        $cacheKey = sprintf('report_%s_%s_%s_%s', 
            $type, 
            $start->format('Y-m-d'), 
            $end->format('Y-m-d'),
            md5(serialize($filters))
        );

        return $this->cacheService->get($cacheKey, function () use ($type, $start, $end, $filters) {
            $method = 'generate' . ucfirst($type) . 'Report';
            return $this->$method($start, $end, $filters);
        }, 3600);
    }

    private function getAppointmentMetrics(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');
        $thisWeek = new \DateTime('this week');
        $nextWeek = new \DateTime('next week');

        return [
            'total' => $qb->select('COUNT(a.id)')
                         ->from(Appointment::class, 'a')
                         ->getQuery()
                         ->getSingleScalarResult(),
            'today' => $qb->select('COUNT(a.id)')
                         ->where('a.dateTime BETWEEN :start AND :end')
                         ->setParameter('start', $today)
                         ->setParameter('end', $tomorrow)
                         ->getQuery()
                         ->getSingleScalarResult(),
            'this_week' => $qb->select('COUNT(a.id)')
                             ->where('a.dateTime BETWEEN :start AND :end')
                             ->setParameter('start', $thisWeek)
                             ->setParameter('end', $nextWeek)
                             ->getQuery()
                             ->getSingleScalarResult(),
            'completion_rate' => $this->calculateCompletionRate(),
            'cancellation_rate' => $this->calculateCancellationRate(),
        ];
    }

    private function getRevenueMetrics(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        $today = new \DateTime('today');
        $thisMonth = new \DateTime('first day of this month');
        $nextMonth = new \DateTime('first day of next month');

        return [
            'today' => $this->calculateRevenue($today, new \DateTime('tomorrow')),
            'this_month' => $this->calculateRevenue($thisMonth, $nextMonth),
            'average_per_appointment' => $this->calculateAverageRevenuePerAppointment(),
            'by_service' => $this->calculateRevenueByService(),
            'by_doctor' => $this->calculateRevenueByDoctor(),
        ];
    }

    private function getPatientMetrics(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        return [
            'total' => $qb->select('COUNT(u.id)')
                         ->from(User::class, 'u')
                         ->where('u.roles LIKE :role')
                         ->setParameter('role', '%ROLE_PATIENT%')
                         ->getQuery()
                         ->getSingleScalarResult(),
            'new_this_month' => $this->getNewPatientsCount(),
            'active' => $this->getActivePatientsCount(),
            'demographics' => $this->getPatientDemographics(),
            'satisfaction' => $this->getPatientSatisfactionMetrics(),
        ];
    }

    private function getServiceMetrics(): array
    {
        return [
            'most_popular' => $this->getMostPopularServices(),
            'least_popular' => $this->getLeastPopularServices(),
            'average_duration' => $this->calculateAverageServiceDuration(),
            'utilization' => $this->calculateServiceUtilization(),
        ];
    }

    private function generateAppointmentsReport(\DateTime $start, \DateTime $end, array $filters): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
           ->from(Appointment::class, 'a')
           ->where('a.dateTime BETWEEN :start AND :end')
           ->setParameter('start', $start)
           ->setParameter('end', $end);

        $this->applyFilters($qb, $filters);

        $appointments = $qb->getQuery()->getResult();

        return [
            'summary' => [
                'total' => count($appointments),
                'completed' => count(array_filter($appointments, fn($a) => $a->getStatus() === 'completed')),
                'cancelled' => count(array_filter($appointments, fn($a) => $a->getStatus() === 'cancelled')),
            ],
            'by_day' => $this->groupAppointmentsByDay($appointments),
            'by_doctor' => $this->groupAppointmentsByDoctor($appointments),
            'by_service' => $this->groupAppointmentsByService($appointments),
            'by_status' => $this->groupAppointmentsByStatus($appointments),
        ];
    }

    private function generateRevenueReport(\DateTime $start, \DateTime $end, array $filters): array
    {
        return [
            'summary' => [
                'total' => $this->calculateRevenue($start, $end),
                'average_per_day' => $this->calculateAverageRevenuePerDay($start, $end),
                'projected' => $this->calculateProjectedRevenue(),
            ],
            'by_service' => $this->calculateRevenueByService($start, $end),
            'by_doctor' => $this->calculateRevenueByDoctor($start, $end),
            'by_payment_method' => $this->calculateRevenueByPaymentMethod($start, $end),
            'trends' => $this->calculateRevenueTrends($start, $end),
        ];
    }

    private function generatePatientsReport(\DateTime $start, \DateTime $end, array $filters): array
    {
        return [
            'summary' => [
                'total_patients' => $this->getTotalPatientsCount(),
                'new_patients' => $this->getNewPatientsCount($start, $end),
                'returning_patients' => $this->getReturningPatientsCount($start, $end),
            ],
            'demographics' => $this->getPatientDemographics($start, $end),
            'visit_frequency' => $this->calculateVisitFrequency($start, $end),
            'retention_rate' => $this->calculateRetentionRate($start, $end),
            'satisfaction_scores' => $this->getPatientSatisfactionScores($start, $end),
        ];
    }

    private function generateServicesReport(\DateTime $start, \DateTime $end, array $filters): array
    {
        return [
            'most_requested' => $this->getMostRequestedServices($start, $end),
            'utilization' => $this->calculateServiceUtilization($start, $end),
            'average_duration' => $this->calculateAverageServiceDuration($start, $end),
            'satisfaction' => $this->getServiceSatisfactionScores($start, $end),
            'revenue' => $this->calculateRevenueByService($start, $end),
        ];
    }

    private function generateDoctorsReport(\DateTime $start, \DateTime $end, array $filters): array
    {
        return [
            'performance' => $this->getDoctorPerformanceMetrics($start, $end),
            'availability' => $this->getDoctorAvailabilityMetrics($start, $end),
            'patient_satisfaction' => $this->getDoctorSatisfactionScores($start, $end),
            'revenue' => $this->calculateRevenueByDoctor($start, $end),
            'specialization_demand' => $this->calculateSpecializationDemand($start, $end),
        ];
    }

    private function generateSatisfactionReport(\DateTime $start, \DateTime $end, array $filters): array
    {
        return [
            'overall_satisfaction' => $this->calculateOverallSatisfaction($start, $end),
            'by_service' => $this->getServiceSatisfactionScores($start, $end),
            'by_doctor' => $this->getDoctorSatisfactionScores($start, $end),
            'feedback_analysis' => $this->analyzeFeedback($start, $end),
            'trends' => $this->calculateSatisfactionTrends($start, $end),
        ];
    }

    private function applyFilters($qb, array $filters): void
    {
        foreach ($filters as $key => $value) {
            switch ($key) {
                case 'doctor':
                    $qb->andWhere('a.doctor = :doctor')
                       ->setParameter('doctor', $value);
                    break;
                case 'service':
                    $qb->andWhere('a.service = :service')
                       ->setParameter('service', $value);
                    break;
                case 'status':
                    $qb->andWhere('a.status = :status')
                       ->setParameter('status', $value);
                    break;
            }
        }
    }

    private function calculateCompletionRate(): float
    {
        $qb = $this->entityManager->createQueryBuilder();
        $total = $qb->select('COUNT(a.id)')
                    ->from(Appointment::class, 'a')
                    ->getQuery()
                    ->getSingleScalarResult();

        if ($total === 0) {
            return 0;
        }

        $completed = $qb->select('COUNT(a.id)')
                       ->where('a.status = :status')
                       ->setParameter('status', 'completed')
                       ->getQuery()
                       ->getSingleScalarResult();

        return ($completed / $total) * 100;
    }

    private function calculateRevenue(\DateTime $start, \DateTime $end): float
    {
        $qb = $this->entityManager->createQueryBuilder();
        return $qb->select('SUM(s.price)')
                  ->from(Appointment::class, 'a')
                  ->join('a.service', 's')
                  ->where('a.dateTime BETWEEN :start AND :end')
                  ->andWhere('a.status = :status')
                  ->setParameter('start', $start)
                  ->setParameter('end', $end)
                  ->setParameter('status', 'completed')
                  ->getQuery()
                  ->getSingleScalarResult() ?? 0;
    }

    private function getNewPatientsCount(\DateTime $start = null, \DateTime $end = null): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(u.id)')
           ->from(User::class, 'u')
           ->where('u.roles LIKE :role');

        if ($start && $end) {
            $qb->andWhere('u.createdAt BETWEEN :start AND :end')
               ->setParameter('start', $start)
               ->setParameter('end', $end);
        }

        return $qb->setParameter('role', '%ROLE_PATIENT%')
                 ->getQuery()
                 ->getSingleScalarResult();
    }

    private function getMostPopularServices(int $limit = 5): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        return $qb->select('s.name, COUNT(a.id) as count')
                  ->from(Service::class, 's')
                  ->leftJoin('s.appointments', 'a')
                  ->groupBy('s.id')
                  ->orderBy('count', 'DESC')
                  ->setMaxResults($limit)
                  ->getQuery()
                  ->getResult();
    }
}
