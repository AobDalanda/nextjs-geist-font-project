<?php

namespace App\Service;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\Exception\TooManyRequestsHttpException;
use Psr\Log\LoggerInterface;

class RateLimiterService
{
    private const LIMITERS = [
        'login' => [
            'policy' => 'sliding_window',
            'limit' => 5,
            'interval' => '15 minutes',
        ],
        'api' => [
            'policy' => 'token_bucket',
            'limit' => 100,
            'interval' => '60 minutes',
        ],
        'appointment_booking' => [
            'policy' => 'fixed_window',
            'limit' => 3,
            'interval' => '24 hours',
        ],
        'contact_form' => [
            'policy' => 'sliding_window',
            'limit' => 5,
            'interval' => '60 minutes',
        ],
        'password_reset' => [
            'policy' => 'fixed_window',
            'limit' => 3,
            'interval' => '60 minutes',
        ],
    ];

    private $limiters;
    private $requestStack;
    private $security;
    private $logger;

    public function __construct(
        array $limiters,
        RequestStack $requestStack,
        Security $security,
        LoggerInterface $logger
    ) {
        $this->limiters = $limiters;
        $this->requestStack = $requestStack;
        $this->security = $security;
        $this->logger = $logger;
    }

    public function checkRateLimit(string $type, ?string $key = null): void
    {
        if (!isset($this->limiters[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown rate limiter type: %s', $type));
        }

        $limiter = $this->limiters[$type];
        $id = $this->createIdentifier($type, $key);

        $limit = $limiter->consume($id);

        if (!$limit->isAccepted()) {
            $this->logRateLimitExceeded($type, $id);
            
            $resetIn = $limit->getRetryAfter()->getTimestamp() - time();
            throw new TooManyRequestsHttpException(
                $resetIn,
                'Too many requests. Please try again later.'
            );
        }

        $this->logRateLimitConsumption($type, $id, $limit->getRemainingTokens());
    }

    public function getRemainingAttempts(string $type, ?string $key = null): int
    {
        if (!isset($this->limiters[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown rate limiter type: %s', $type));
        }

        $limiter = $this->limiters[$type];
        $id = $this->createIdentifier($type, $key);

        return $limiter->consume($id, 0)->getRemainingTokens();
    }

    public function resetRateLimit(string $type, ?string $key = null): void
    {
        if (!isset($this->limiters[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown rate limiter type: %s', $type));
        }

        $limiter = $this->limiters[$type];
        $id = $this->createIdentifier($type, $key);

        $limiter->reset($id);
        
        $this->logger->info('Rate limit reset', [
            'type' => $type,
            'identifier' => $id,
        ]);
    }

    public function isRateLimited(string $type, ?string $key = null): bool
    {
        if (!isset($this->limiters[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown rate limiter type: %s', $type));
        }

        $limiter = $this->limiters[$type];
        $id = $this->createIdentifier($type, $key);

        return !$limiter->consume($id, 0)->isAccepted();
    }

    public function getWaitDuration(string $type, ?string $key = null): ?\DateInterval
    {
        if (!isset($this->limiters[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown rate limiter type: %s', $type));
        }

        $limiter = $this->limiters[$type];
        $id = $this->createIdentifier($type, $key);

        $limit = $limiter->consume($id, 0);
        
        if ($limit->isAccepted()) {
            return null;
        }

        return $limit->getRetryAfter()->diff(new \DateTime());
    }

    private function createIdentifier(string $type, ?string $key = null): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $user = $this->security->getUser();

        $parts = [
            $type,
            $key,
            $user ? $user->getId() : 'anonymous',
            $request ? $request->getClientIp() : 'unknown',
        ];

        return implode('_', array_filter($parts));
    }

    private function logRateLimitExceeded(string $type, string $id): void
    {
        $this->logger->warning('Rate limit exceeded', [
            'type' => $type,
            'identifier' => $id,
            'ip' => $this->requestStack->getCurrentRequest()?->getClientIp(),
            'user' => $this->security->getUser()?->getUserIdentifier(),
        ]);
    }

    private function logRateLimitConsumption(string $type, string $id, int $remaining): void
    {
        $this->logger->debug('Rate limit consumption', [
            'type' => $type,
            'identifier' => $id,
            'remaining' => $remaining,
            'ip' => $this->requestStack->getCurrentRequest()?->getClientIp(),
            'user' => $this->security->getUser()?->getUserIdentifier(),
        ]);
    }

    public static function getDefaultLimiterConfigs(): array
    {
        return self::LIMITERS;
    }

    public function getLimiterInfo(string $type, ?string $key = null): array
    {
        if (!isset($this->limiters[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown rate limiter type: %s', $type));
        }

        $limiter = $this->limiters[$type];
        $id = $this->createIdentifier($type, $key);
        $limit = $limiter->consume($id, 0);

        return [
            'accepted' => $limit->isAccepted(),
            'remaining' => $limit->getRemainingTokens(),
            'retry_after' => $limit->isAccepted() ? null : $limit->getRetryAfter(),
            'limit' => self::LIMITERS[$type]['limit'],
            'interval' => self::LIMITERS[$type]['interval'],
        ];
    }
}
