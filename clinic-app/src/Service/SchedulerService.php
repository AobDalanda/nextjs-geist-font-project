<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Psr\Log\LoggerInterface;
use App\Entity\Appointment;
use App\Entity\ScheduledTask;
use App\Entity\User;

class SchedulerService
{
    private const TASK_TYPES = [
        'appointment_reminder',
        'backup',
        'report_generation',
        'data_cleanup',
        'cache_warmup',
        'system_health_check',
        'email_queue_process',
        'analytics_update',
    ];

    private const INTERVALS = [
        'every_minute' => '* * * * *',
        'every_5_minutes' => '*/5 * * * *',
        'every_15_minutes' => '*/15 * * * *',
        'every_30_minutes' => '*/30 * * * *',
        'hourly' => '0 * * * *',
        'daily' => '0 0 * * *',
        'weekly' => '0 0 * * 0',
        'monthly' => '0 0 1 * *',
    ];

    private $entityManager;
    private $logger;
    private $notificationService;
    private $backupService;
    private $analyticsService;
    private $settingsService;
    private $lockFactory;

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        NotificationService $notificationService,
        BackupService $backupService,
        AnalyticsService $analyticsService,
        SettingsService $settingsService
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
        $this->backupService = $backupService;
        $this->analyticsService = $analyticsService;
        $this->settingsService = $settingsService;
        $this->lockFactory = new LockFactory(new FlockStore());
    }

    public function executeDueTasks(): void
    {
        $lock = $this->lockFactory->createLock('scheduler_execution');
        
        if (!$lock->acquire()) {
            $this->logger->info('Scheduler is already running');
            return;
        }

        try {
            $this->logger->info('Starting scheduled tasks execution');

            $tasks = $this->getDueTasks();
            foreach ($tasks as $task) {
                $this->executeTask($task);
            }

            $this->logger->info('Finished executing scheduled tasks');
        } catch (\Exception $e) {
            $this->logger->error('Error executing scheduled tasks', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }
    }

    public function scheduleTask(string $type, array $parameters = [], string $interval = 'daily'): ScheduledTask
    {
        $this->validateTaskType($type);
        $this->validateInterval($interval);

        try {
            $task = new ScheduledTask();
            $task->setType($type)
                 ->setParameters($parameters)
                 ->setInterval($interval)
                 ->setNextRun($this->calculateNextRun($interval))
                 ->setEnabled(true);

            $this->entityManager->persist($task);
            $this->entityManager->flush();

            $this->logger->info('Task scheduled', [
                'type' => $type,
                'interval' => $interval,
                'next_run' => $task->getNextRun()->format('Y-m-d H:i:s'),
            ]);

            return $task;
        } catch (\Exception $e) {
            $this->logger->error('Failed to schedule task', [
                'error' => $e->getMessage(),
                'type' => $type,
            ]);
            throw $e;
        }
    }

    public function executeTask(ScheduledTask $task): void
    {
        $lock = $this->lockFactory->createLock('task_' . $task->getId());
        
        if (!$lock->acquire()) {
            $this->logger->info('Task is already running', [
                'task_id' => $task->getId(),
            ]);
            return;
        }

        try {
            $this->logger->info('Executing task', [
                'task_id' => $task->getId(),
                'type' => $task->getType(),
            ]);

            $task->setLastRun(new \DateTime())
                 ->setStatus('running');
            $this->entityManager->flush();

            switch ($task->getType()) {
                case 'appointment_reminder':
                    $this->processAppointmentReminders();
                    break;
                case 'backup':
                    $this->processBackup($task->getParameters());
                    break;
                case 'report_generation':
                    $this->generateReports($task->getParameters());
                    break;
                case 'data_cleanup':
                    $this->performDataCleanup();
                    break;
                case 'cache_warmup':
                    $this->warmupCache();
                    break;
                case 'system_health_check':
                    $this->checkSystemHealth();
                    break;
                case 'email_queue_process':
                    $this->processEmailQueue();
                    break;
                case 'analytics_update':
                    $this->updateAnalytics();
                    break;
            }

            $task->setStatus('completed')
                 ->setLastSuccess(new \DateTime())
                 ->setNextRun($this->calculateNextRun($task->getInterval()))
                 ->setError(null);

            $this->entityManager->flush();

            $this->logger->info('Task completed successfully', [
                'task_id' => $task->getId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Task execution failed', [
                'task_id' => $task->getId(),
                'error' => $e->getMessage(),
            ]);

            $task->setStatus('failed')
                 ->setError($e->getMessage())
                 ->setNextRun($this->calculateNextRun($task->getInterval()));

            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }

    private function getDueTasks(): array
    {
        return $this->entityManager->getRepository(ScheduledTask::class)
            ->createQueryBuilder('t')
            ->where('t.enabled = :enabled')
            ->andWhere('t.nextRun <= :now')
            ->andWhere('t.status != :status')
            ->setParameters([
                'enabled' => true,
                'now' => new \DateTime(),
                'status' => 'running',
            ])
            ->getQuery()
            ->getResult();
    }

    private function processAppointmentReminders(): void
    {
        $tomorrow = new \DateTime('tomorrow');
        $appointments = $this->entityManager->getRepository(Appointment::class)
            ->findUpcomingAppointments($tomorrow);

        foreach ($appointments as $appointment) {
            $this->notificationService->sendAppointmentReminder($appointment);
        }
    }

    private function processBackup(array $parameters): void
    {
        $type = $parameters['type'] ?? 'full';
        $this->backupService->createBackup($type, $parameters);
    }

    private function generateReports(array $parameters): void
    {
        $reportTypes = $parameters['types'] ?? ['appointments', 'revenue'];
        $date = new \DateTime();

        foreach ($reportTypes as $type) {
            $this->analyticsService->generateReport($type, $date, $date);
        }
    }

    private function performDataCleanup(): void
    {
        // Clean old sessions
        $this->entityManager->getConnection()->executeQuery(
            'DELETE FROM sessions WHERE sess_lifetime < UNIX_TIMESTAMP()'
        );

        // Clean old logs
        $retentionDays = $this->settingsService->get('log_retention_days', 30);
        $this->entityManager->getConnection()->executeQuery(
            'DELETE FROM log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$retentionDays]
        );

        // Clean temporary files
        $tmpDir = $this->settingsService->get('temporary_files_directory');
        if ($tmpDir && is_dir($tmpDir)) {
            $finder = new \Symfony\Component\Finder\Finder();
            $finder->files()
                   ->in($tmpDir)
                   ->date('< 1 day ago');

            foreach ($finder as $file) {
                unlink($file->getRealPath());
            }
        }
    }

    private function warmupCache(): void
    {
        // Warm up application cache
        $this->entityManager->getRepository(User::class)->findAll();
        $this->entityManager->getRepository(Appointment::class)->findAll();
        
        // Warm up configuration
        $this->settingsService->getAllSettings();
    }

    private function checkSystemHealth(): void
    {
        $healthStatus = [
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'disk' => $this->checkDiskSpace(),
            'services' => $this->checkServicesHealth(),
        ];

        if (in_array(false, $healthStatus)) {
            $this->notificationService->notifyAdministrators(
                'System Health Alert',
                'One or more system components are not healthy. Please check the monitoring dashboard.'
            );
        }
    }

    private function processEmailQueue(): void
    {
        // Process pending email notifications
        $this->notificationService->processQueue();
    }

    private function updateAnalytics(): void
    {
        $this->analyticsService->getDashboardMetrics();
    }

    private function calculateNextRun(string $interval): \DateTime
    {
        $now = new \DateTime();
        
        switch ($interval) {
            case 'every_minute':
                return $now->modify('+1 minute');
            case 'every_5_minutes':
                return $now->modify('+5 minutes');
            case 'every_15_minutes':
                return $now->modify('+15 minutes');
            case 'every_30_minutes':
                return $now->modify('+30 minutes');
            case 'hourly':
                return $now->modify('+1 hour');
            case 'daily':
                return $now->modify('+1 day')->setTime(0, 0);
            case 'weekly':
                return $now->modify('next monday')->setTime(0, 0);
            case 'monthly':
                return $now->modify('first day of next month')->setTime(0, 0);
            default:
                throw new \InvalidArgumentException('Invalid interval');
        }
    }

    private function validateTaskType(string $type): void
    {
        if (!in_array($type, self::TASK_TYPES)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid task type. Allowed types: %s',
                implode(', ', self::TASK_TYPES)
            ));
        }
    }

    private function validateInterval(string $interval): void
    {
        if (!isset(self::INTERVALS[$interval])) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid interval. Allowed intervals: %s',
                implode(', ', array_keys(self::INTERVALS))
            ));
        }
    }

    private function checkDatabaseHealth(): bool
    {
        try {
            $this->entityManager->getConnection()->connect();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkCacheHealth(): bool
    {
        try {
            $key = 'health_check_' . uniqid();
            $this->cache->set($key, true, 30);
            return $this->cache->get($key) === true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkDiskSpace(): bool
    {
        $minSpace = 500 * 1024 * 1024; // 500MB
        return disk_free_space('/') > $minSpace;
    }

    private function checkServicesHealth(): bool
    {
        // Check critical services
        $services = [
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'email' => $this->checkEmailService(),
            'sms' => $this->checkSmsService(),
        ];

        return !in_array(false, $services);
    }
}
