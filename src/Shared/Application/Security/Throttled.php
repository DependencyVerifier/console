<?php

declare(strict_types=1);

namespace Shared\Application\Security;

/**
 * Raised by ordinary commands (password reset request, invitation resend, email code resend) when a limiter
 * refuses. Code verifications return a `Throttled` result instead.
 */
final class Throttled extends \RuntimeException
{
    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct('Too many attempts, try again later.');
    }
}
