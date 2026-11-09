<?php

declare(strict_types=1);

namespace Shared\Infrastructure\RateLimiting;

use Doctrine\DBAL\Exception as DbalException;
use Shared\Application\Security\RateLimiterStorageUnavailable;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;

/**
 * Single translator of limiter storage / lock failures (DBAL errors, lock_timeout 55P03, statement_timeout 57014,
 * LockAcquiringException) into RateLimiterStorageUnavailable. Shared by the throttle adapter and the login limiter.
 */
final class RateLimiterStorageFailure
{
    public static function translate(\Throwable $failure): \Throwable
    {
        return $failure instanceof DbalException || $failure instanceof LockException || $failure instanceof \PDOException
            ? new RateLimiterStorageUnavailable('Rate limiter storage unavailable.', 0, $failure)
            : $failure;
    }
}
