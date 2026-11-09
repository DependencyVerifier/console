<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

/**
 * Result of a code verification (TOTP, TOTP enrolment, email code, backup code). Never an exception: a wrong code
 * must commit its counter. `Throttled` comes from the limiters, not from the aggregate.
 */
enum CodeVerificationOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Throttled = 'throttled';

    public function isAccepted(): bool
    {
        return self::Accepted === $this;
    }
}
