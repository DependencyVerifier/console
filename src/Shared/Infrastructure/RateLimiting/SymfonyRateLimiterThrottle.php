<?php

declare(strict_types=1);

namespace Shared\Infrastructure\RateLimiting;

use Psr\Clock\ClockInterface;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Security\AttemptThrottle;
use Shared\Application\Security\ThrottleDecision;
use Shared\Application\Security\ThrottleName;
use Shared\Application\Security\ThrottleSubject;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Adapter of AttemptThrottle on the named limiters of rate_limiter.yaml. Keys are HMAC-ed (current pepper only:
 * rotating it resets the windows), so the storage holds no email or IP in clear.
 *
 * The named limiters are wired in config/services.yaml (service locator keyed by ThrottleName values).
 *
 * peek() is consume(0) read as `remaining > 0`: isAccepted() is always true for zero tokens in SlidingWindowLimiter.
 */
final readonly class SymfonyRateLimiterThrottle implements AttemptThrottle
{
    /**
     * @param ServiceLocator<RateLimiterFactoryInterface> $limiters
     */
    public function __construct(
        private ServiceLocator $limiters,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function peek(ThrottleName $name, ThrottleSubject $subject): ThrottleDecision
    {
        $limit = $this->limit($name, $subject, 0);

        return $limit->getRemainingTokens() > 0
            ? ThrottleDecision::accepted($this->clock->now())
            : ThrottleDecision::refused($limit->getRetryAfter());
    }

    public function consume(ThrottleName $name, ThrottleSubject $subject, int $tokens = 1): ThrottleDecision
    {
        $limit = $this->limit($name, $subject, $tokens);

        return match (true) {
            !$limit->isAccepted() => ThrottleDecision::refused($limit->getRetryAfter()),
            0 === $limit->getRemainingTokens() => ThrottleDecision::acceptedAndExhausted($limit->getRetryAfter()),
            default => ThrottleDecision::accepted($this->clock->now()),
        };
    }

    private function limit(ThrottleName $name, ThrottleSubject $subject, int $tokens): RateLimit
    {
        try {
            /** @var RateLimiterFactoryInterface $factory */
            $factory = $this->limiters->get($name->value);

            return $factory->create($this->hasher->hash($subject->key))->consume($tokens);
        } catch (\Throwable $failure) {
            throw RateLimiterStorageFailure::translate($failure);
        }
    }
}
