<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class CacheService
{
    private const CACHE_TTL = 3600; // 1 hour default TTL
    private const CACHE_TAGS = [
        'appointments',
        'doctors',
        'patients',
        'services',
        'statistics',
        'user_data',
    ];

    private $cache;
    private $logger;
    private $security;
    private $requestStack;

    public function __construct(
        CacheInterface $cache,
        LoggerInterface $logger,
        Security $security,
        RequestStack $requestStack
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->security = $security;
        $this->requestStack = $requestStack;
    }

    public function get(string $key, callable $callback, int $ttl = self::CACHE_TTL, array $tags = []): mixed
    {
        try {
            return $this->cache->get($this->normalizeKey($key), function (ItemInterface $item) use ($callback, $ttl, $tags) {
                $item->expiresAfter($ttl);
                
                if (!empty($tags)) {
                    $item->tag($tags);
                }

                $result = $callback();
                $this->logCacheOperation('set', $item->getKey(), $tags);
                
                return $result;
            });
        } catch (\Exception $e) {
            $this->logger->error('Cache error: ' . $e->getMessage(), [
                'key' => $key,
                'tags' => $tags,
                'exception' => $e,
            ]);

            // Return fresh data if cache fails
            return $callback();
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->logCacheOperation('delete', $key);
            return $this->cache->delete($this->normalizeKey($key));
        } catch (\Exception $e) {
            $this->logger->error('Cache deletion error: ' . $e->getMessage(), [
                'key' => $key,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function invalidateTags(array $tags): bool
    {
        try {
            $this->logCacheOperation('invalidate_tags', '', $tags);
            $this->cache->invalidateTags($tags);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Cache tag invalidation error: ' . $e->getMessage(), [
                'tags' => $tags,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $this->logCacheOperation('clear');
            $this->cache->clear();
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Cache clear error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return false;
        }
    }

    // Specialized cache methods for common operations

    public function getDoctorSchedule(int $doctorId, \DateTime $date): array
    {
        $key = sprintf('doctor_schedule_%d_%s', $doctorId, $date->format('Y-m-d'));
        return $this->get($key, function () use ($doctorId, $date) {
            // Implementation would be provided by the caller
            return [];
        }, self::CACHE_TTL, ['doctors', 'appointments']);
    }

    public function getAvailableSlots(int $doctorId, \DateTime $date): array
    {
        $key = sprintf('available_slots_%d_%s', $doctorId, $date->format('Y-m-d'));
        return $this->get($key, function () use ($doctorId, $date) {
            // Implementation would be provided by the caller
            return [];
        }, 900); // 15 minutes TTL for availability data
    }

    public function getUserDashboardData(int $userId): array
    {
        $key = sprintf('user_dashboard_%d', $userId);
        return $this->get($key, function () use ($userId) {
            // Implementation would be provided by the caller
            return [];
        }, 300, ['user_data']); // 5 minutes TTL for dashboard data
    }

    public function getStatistics(string $type, \DateTime $start, \DateTime $end): array
    {
        $key = sprintf('statistics_%s_%s_%s', 
            $type,
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );
        return $this->get($key, function () use ($type, $start, $end) {
            // Implementation would be provided by the caller
            return [];
        }, 3600, ['statistics']); // 1 hour TTL for statistics
    }

    public function getMedicalServices(bool $activeOnly = true): array
    {
        $key = 'medical_services' . ($activeOnly ? '_active' : '_all');
        return $this->get($key, function () use ($activeOnly) {
            // Implementation would be provided by the caller
            return [];
        }, 3600, ['services']);
    }

    public function getDoctorList(array $criteria = []): array
    {
        $key = 'doctor_list_' . md5(serialize($criteria));
        return $this->get($key, function () use ($criteria) {
            // Implementation would be provided by the caller
            return [];
        }, 1800, ['doctors']); // 30 minutes TTL
    }

    // Cache warming methods

    public function warmUpCommonCaches(): void
    {
        try {
            // Warm up frequently accessed data
            $this->getMedicalServices();
            $this->getDoctorList();
            
            // Warm up next 7 days of availability for active doctors
            $start = new \DateTime();
            $end = (new \DateTime())->modify('+7 days');
            
            while ($start <= $end) {
                foreach ($this->getDoctorList(['isActive' => true]) as $doctor) {
                    $this->getDoctorSchedule($doctor['id'], clone $start);
                }
                $start->modify('+1 day');
            }

            $this->logger->info('Cache warm-up completed successfully');
        } catch (\Exception $e) {
            $this->logger->error('Cache warm-up failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    private function normalizeKey(string $key): string
    {
        // Add prefix for different environments
        $prefix = $_ENV['APP_ENV'] ?? 'dev';
        
        // Add user context if authenticated
        $user = $this->security->getUser();
        $userContext = $user ? '_u' . $user->getId() : '_anon';
        
        // Add language context if available
        $request = $this->requestStack->getCurrentRequest();
        $locale = $request ? $request->getLocale() : 'fr';
        
        return sprintf('%s_%s_%s_%s', $prefix, $locale, $userContext, $key);
    }

    private function logCacheOperation(string $operation, string $key = '', array $tags = []): void
    {
        $this->logger->debug('Cache operation: ' . $operation, [
            'key' => $key,
            'tags' => $tags,
            'user' => $this->security->getUser()?->getUsername(),
        ]);
    }
}
