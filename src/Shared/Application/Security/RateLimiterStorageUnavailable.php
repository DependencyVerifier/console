<?php

declare(strict_types=1);

namespace Shared\Application\Security;

/**
 * The limiter storage or its lock could not be read or written: fail-closed, the attempt is refused (HTTP 503),
 * never treated as "within the limit".
 */
final class RateLimiterStorageUnavailable extends \RuntimeException
{
}
