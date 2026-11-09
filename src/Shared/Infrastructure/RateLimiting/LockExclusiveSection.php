<?php

declare(strict_types=1);

namespace Shared\Infrastructure\RateLimiting;

use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Security\Contended;
use Shared\Application\Security\ExclusiveSection;
use Shared\Application\Security\ThrottleSubject;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Advisory lock on the "rate_limiter" connection, outside the command transaction. Lock::acquire(true) waits
 * forever, so acquisition is a bounded loop of acquire(false) with backoff (2 s). Keys live in their own namespace
 * (`2fa-ip:` + HMAC) to never collide with the limiters' internal locks. Released in `finally`; never retried after
 * a reconnection (fail-closed instead).
 */
final readonly class LockExclusiveSection implements ExclusiveSection
{
    private const float MAX_WAIT_SECONDS = 2.0;

    public function __construct(
        #[Autowire(service: 'app.rate_limiter.lock_factory')]
        private LockFactory $locks,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function run(ThrottleSubject $subject, callable $section): mixed
    {
        try {
            $lock = $this->locks->createLock('2fa-ip:'.$this->hasher->hash($subject->key), 30.0);
            $deadline = (float) $this->clock->now()->format('U.u') + self::MAX_WAIT_SECONDS;
            $pause = 0.05;
            while (!$lock->acquire(false)) {
                if ((float) $this->clock->now()->format('U.u') >= $deadline) {
                    throw new Contended('Lock contention.');
                }

                $this->clock->sleep($pause);
                $pause = min($pause * 2, 0.4);
            }
        } catch (Contended $contended) {
            throw $contended;
        } catch (\Throwable $failure) {
            throw RateLimiterStorageFailure::translate($failure);
        }

        try {
            return $section();
        } finally {
            $lock->release();
        }
    }
}
