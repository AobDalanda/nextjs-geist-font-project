<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\Response;

class MonitoringService
{
    private const ERROR_LEVELS = [
        'critical' => 1,
        'error' => 2,
        'warning' => 3,
        'info' => 4,
        'debug' => 5,
    ];

    private const PERFORMANCE_THRESHOLDS = [
        'response_time' => 1000, // milliseconds
        'memory_usage' => 128 * 1024 * 1024, // 128MB
        'cpu_usage' => 80, // percentage
        'database_queries' => 100,
    ];

    private $logger;
    private $requestStack;
    private $security;
    private $params;
    private $mailer;
    private $settingsService;
    private $errors = [];
    private $metrics = [];
    private $startTime;

    public function __construct(
        LoggerInterface $logger,
        RequestStack $requestStack,
        Security $security,
        ParameterBagInterface $params,
        MailerInterface $mailer,
        SettingsService $settingsService
    ) {
        $this->logger = $logger;
        $this->requestStack = $requestStack;
        $this->security = $security;
        $this->params = $params;
        $this->mailer = $mailer;
        $this->settingsService = $settingsService;
        $this->startTime = microtime(true);
    }

    public function trackError(
        \Throwable $exception,
        string $level = 'error',
        array $context = []
    ): void {
        $request = $this->requestStack->getCurrentRequest();
        $user = $this->security->getUser();

        $error = [
            'timestamp' => new \DateTime(),
            'level' => $level,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'request' => [
                'method' => $request?->getMethod(),
                'url' => $request?->getUri(),
                'ip' => $request?->getClientIp(),
                'user_agent' => $request?->headers->get('User-Agent'),
            ],
            'user' => $user ? [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ] : null,
            'context' => $context,
        ];

        $this->errors[] = $error;

        // Log the error
        $this->logger->log($level, $exception->getMessage(), $error);

        // Notify if critical
        if (self::ERROR_LEVELS[$level] <= self::ERROR_LEVELS['error']) {
            $this->notifyError($error);
        }
    }

    public function trackMetric(string $name, $value, array $tags = []): void
    {
        $metric = [
            'timestamp' => new \DateTime(),
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
        ];

        $this->metrics[] = $metric;

        // Check thresholds
        if (isset(self::PERFORMANCE_THRESHOLDS[$name]) && $value > self::PERFORMANCE_THRESHOLDS[$name]) {
            $this->logger->warning("Performance threshold exceeded for {$name}", $metric);
        }
    }

    public function getRequestMetrics(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        
        if (!$request) {
            return [];
        }

        return [
            'response_time' => (microtime(true) - $this->startTime) * 1000,
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_usage' => $this->getCpuUsage(),
            'request_method' => $request->getMethod(),
            'request_path' => $request->getPathInfo(),
            'status_code' => $request->attributes->get('_status_code'),
            'ip_address' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
        ];
    }

    public function checkSystemHealth(): array
    {
        $checks = [
            'database' => $this->checkDatabaseConnection(),
            'cache' => $this->checkCacheService(),
            'storage' => $this->checkStorageSpace(),
            'memory' => $this->checkMemoryUsage(),
            'cpu' => $this->checkCpuUsage(),
            'services' => $this->checkExternalServices(),
        ];

        $status = !in_array(false, array_column($checks, 'status'));

        return [
            'status' => $status ? 'healthy' : 'unhealthy',
            'timestamp' => new \DateTime(),
            'checks' => $checks,
        ];
    }

    public function getErrorStats(?\DateTime $since = null): array
    {
        $errors = array_filter($this->errors, function ($error) use ($since) {
            return $since === null || $error['timestamp'] >= $since;
        });

        $stats = [
            'total' => count($errors),
            'by_level' => [],
            'by_code' => [],
            'recent' => array_slice($errors, -10),
        ];

        foreach ($errors as $error) {
            $stats['by_level'][$error['level']] = ($stats['by_level'][$error['level']] ?? 0) + 1;
            $stats['by_code'][$error['code']] = ($stats['by_code'][$error['code']] ?? 0) + 1;
        }

        return $stats;
    }

    public function getPerformanceStats(?\DateTime $since = null): array
    {
        $metrics = array_filter($this->metrics, function ($metric) use ($since) {
            return $since === null || $metric['timestamp'] >= $since;
        });

        $stats = [
            'total_requests' => 0,
            'average_response_time' => 0,
            'max_memory_usage' => 0,
            'average_cpu_usage' => 0,
        ];

        foreach ($metrics as $metric) {
            switch ($metric['name']) {
                case 'response_time':
                    $stats['total_requests']++;
                    $stats['average_response_time'] += $metric['value'];
                    break;
                case 'memory_usage':
                    $stats['max_memory_usage'] = max($stats['max_memory_usage'], $metric['value']);
                    break;
                case 'cpu_usage':
                    $stats['average_cpu_usage'] += $metric['value'];
                    break;
            }
        }

        if ($stats['total_requests'] > 0) {
            $stats['average_response_time'] /= $stats['total_requests'];
            $stats['average_cpu_usage'] /= $stats['total_requests'];
        }

        return $stats;
    }

    private function notifyError(array $error): void
    {
        try {
            $adminEmail = $this->settingsService->get('admin_email');
            if (!$adminEmail) {
                return;
            }

            $email = (new Email())
                ->from('monitoring@clinique.fr')
                ->to($adminEmail)
                ->subject('Error Alert: ' . $error['message'])
                ->html($this->renderErrorEmail($error));

            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send error notification', [
                'error' => $error,
                'notification_error' => $e->getMessage(),
            ]);
        }
    }

    private function renderErrorEmail(array $error): string
    {
        return <<<HTML
            <h1>Error Alert</h1>
            <p><strong>Message:</strong> {$error['message']}</p>
            <p><strong>Level:</strong> {$error['level']}</p>
            <p><strong>Time:</strong> {$error['timestamp']->format('Y-m-d H:i:s')}</p>
            <p><strong>File:</strong> {$error['file']}:{$error['line']}</p>
            <p><strong>URL:</strong> {$error['request']['url']}</p>
            <pre>{$error['trace']}</pre>
        HTML;
    }

    private function checkDatabaseConnection(): array
    {
        try {
            $this->entityManager->getConnection()->connect();
            return ['status' => true, 'message' => 'Database connection successful'];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => 'Database connection failed: ' . $e->getMessage()];
        }
    }

    private function checkCacheService(): array
    {
        try {
            $key = 'health_check_' . uniqid();
            $this->cache->set($key, true, 30);
            $result = $this->cache->get($key);
            return ['status' => $result === true, 'message' => 'Cache service operational'];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => 'Cache service failed: ' . $e->getMessage()];
        }
    }

    private function checkStorageSpace(): array
    {
        $path = $this->params->get('kernel.project_dir');
        $free = disk_free_space($path);
        $total = disk_total_space($path);
        $used = $total - $free;
        $usedPercentage = ($used / $total) * 100;

        return [
            'status' => $usedPercentage < 90,
            'message' => sprintf('Storage space: %.2f%% used', $usedPercentage),
            'details' => [
                'free' => $free,
                'total' => $total,
                'used' => $used,
                'percentage' => $usedPercentage,
            ],
        ];
    }

    private function checkMemoryUsage(): array
    {
        $memoryLimit = $this->getMemoryLimitInBytes();
        $currentUsage = memory_get_usage(true);
        $peakUsage = memory_get_peak_usage(true);
        $percentage = ($peakUsage / $memoryLimit) * 100;

        return [
            'status' => $percentage < 90,
            'message' => sprintf('Memory usage: %.2f%% of limit', $percentage),
            'details' => [
                'current' => $currentUsage,
                'peak' => $peakUsage,
                'limit' => $memoryLimit,
                'percentage' => $percentage,
            ],
        ];
    }

    private function checkCpuUsage(): array
    {
        $usage = $this->getCpuUsage();
        
        return [
            'status' => $usage < 90,
            'message' => sprintf('CPU usage: %.2f%%', $usage),
            'details' => [
                'usage' => $usage,
            ],
        ];
    }

    private function checkExternalServices(): array
    {
        $services = [
            'mailer' => $this->checkMailerService(),
            'sms' => $this->checkSmsService(),
            'payment' => $this->checkPaymentService(),
        ];

        $allOperational = !in_array(false, array_column($services, 'status'));

        return [
            'status' => $allOperational,
            'message' => $allOperational ? 'All services operational' : 'Some services are down',
            'details' => $services,
        ];
    }

    private function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) substr($memoryLimit, 0, -1);
        
        switch ($unit) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    private function getCpuUsage(): float
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load[0] * 100;
        }
        
        return 0;
    }
}
