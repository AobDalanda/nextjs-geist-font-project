<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class AuditService
{
    private const LOG_TYPES = [
        'security' => 'Sécurité',
        'data' => 'Données',
        'system' => 'Système',
        'user' => 'Utilisateur',
        'appointment' => 'Rendez-vous',
        'medical' => 'Médical',
    ];

    private $entityManager;
    private $logger;
    private $security;
    private $requestStack;
    private $tokenStorage;

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        Security $security,
        RequestStack $requestStack,
        TokenStorageInterface $tokenStorage
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->security = $security;
        $this->requestStack = $requestStack;
        $this->tokenStorage = $tokenStorage;
    }

    public function logSecurityEvent(string $action, array $data = [], string $level = 'info'): void
    {
        $this->logEvent('security', $action, $data, $level);
    }

    public function logDataEvent(string $action, array $data = [], string $level = 'info'): void
    {
        $this->logEvent('data', $action, $data, $level);
    }

    public function logSystemEvent(string $action, array $data = [], string $level = 'info'): void
    {
        $this->logEvent('system', $action, $data, $level);
    }

    public function logUserEvent(string $action, array $data = [], string $level = 'info'): void
    {
        $this->logEvent('user', $action, $data, $level);
    }

    public function logAppointmentEvent(string $action, array $data = [], string $level = 'info'): void
    {
        $this->logEvent('appointment', $action, $data, $level);
    }

    public function logMedicalEvent(string $action, array $data = [], string $level = 'info'): void
    {
        $this->logEvent('medical', $action, $data, $level);
    }

    private function logEvent(string $type, string $action, array $data = [], string $level = 'info'): void
    {
        $user = $this->security->getUser();
        $request = $this->requestStack->getCurrentRequest();

        $logData = [
            'timestamp' => new \DateTime(),
            'type' => $type,
            'action' => $action,
            'user_id' => $user ? $user->getId() : null,
            'user_email' => $user ? $user->getEmail() : null,
            'ip_address' => $request ? $request->getClientIp() : null,
            'user_agent' => $request ? $request->headers->get('User-Agent') : null,
            'request_method' => $request ? $request->getMethod() : null,
            'request_path' => $request ? $request->getPathInfo() : null,
            'data' => $data,
        ];

        // Log to system logger
        $this->logger->$level(sprintf(
            '[%s] %s - User: %s - %s',
            strtoupper($type),
            $action,
            $user ? $user->getEmail() : 'anonymous',
            json_encode($data)
        ), $logData);

        // Store in database
        $this->persistAuditLog($logData);
    }

    private function persistAuditLog(array $logData): void
    {
        try {
            $auditLog = new \App\Entity\AuditLog();
            $auditLog->setTimestamp($logData['timestamp'])
                    ->setType($logData['type'])
                    ->setAction($logData['action'])
                    ->setUserId($logData['user_id'])
                    ->setUserEmail($logData['user_email'])
                    ->setIpAddress($logData['ip_address'])
                    ->setUserAgent($logData['user_agent'])
                    ->setRequestMethod($logData['request_method'])
                    ->setRequestPath($logData['request_path'])
                    ->setData(json_encode($logData['data']));

            $this->entityManager->persist($auditLog);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('Failed to persist audit log: ' . $e->getMessage(), [
                'exception' => $e,
                'log_data' => $logData,
            ]);
        }
    }

    public function getAuditLogs(array $criteria = [], array $orderBy = ['timestamp' => 'DESC'], int $limit = 100, int $offset = 0): array
    {
        return $this->entityManager->getRepository(\App\Entity\AuditLog::class)
            ->findBy($criteria, $orderBy, $limit, $offset);
    }

    public function getAuditLogsForUser(User $user, array $orderBy = ['timestamp' => 'DESC'], int $limit = 100): array
    {
        return $this->getAuditLogs(['user_id' => $user->getId()], $orderBy, $limit);
    }

    public function getAuditLogsByType(string $type, array $orderBy = ['timestamp' => 'DESC'], int $limit = 100): array
    {
        return $this->getAuditLogs(['type' => $type], $orderBy, $limit);
    }

    public function getAuditLogsByDateRange(\DateTime $start, \DateTime $end, array $orderBy = ['timestamp' => 'DESC']): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        return $qb->select('a')
            ->from(\App\Entity\AuditLog::class, 'a')
            ->where('a.timestamp BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('a.timestamp', $orderBy['timestamp'])
            ->getQuery()
            ->getResult();
    }

    public function getSecurityEvents(int $limit = 100): array
    {
        return $this->getAuditLogsByType('security', ['timestamp' => 'DESC'], $limit);
    }

    public function getUserActivityTimeline(User $user, \DateTime $start = null, \DateTime $end = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
           ->from(\App\Entity\AuditLog::class, 'a')
           ->where('a.user_id = :userId')
           ->setParameter('userId', $user->getId())
           ->orderBy('a.timestamp', 'DESC');

        if ($start) {
            $qb->andWhere('a.timestamp >= :start')
               ->setParameter('start', $start);
        }

        if ($end) {
            $qb->andWhere('a.timestamp <= :end')
               ->setParameter('end', $end);
        }

        return $qb->getQuery()->getResult();
    }

    public function getSystemHealthLogs(\DateTime $start = null, \DateTime $end = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
           ->from(\App\Entity\AuditLog::class, 'a')
           ->where('a.type = :type')
           ->setParameter('type', 'system')
           ->orderBy('a.timestamp', 'DESC');

        if ($start) {
            $qb->andWhere('a.timestamp >= :start')
               ->setParameter('start', $start);
        }

        if ($end) {
            $qb->andWhere('a.timestamp <= :end')
               ->setParameter('end', $end);
        }

        return $qb->getQuery()->getResult();
    }

    public function cleanupOldLogs(int $daysToKeep = 90): int
    {
        $cutoffDate = new \DateTime("-{$daysToKeep} days");
        
        $qb = $this->entityManager->createQueryBuilder();
        return $qb->delete(\App\Entity\AuditLog::class, 'a')
            ->where('a.timestamp < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();
    }
}
