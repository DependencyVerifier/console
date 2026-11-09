<?php

declare(strict_types=1);

namespace Identity\Application\SecondFactor;

use Shared\Application\Audit\SelfAuditedCommand;

/**
 * The four code gestures (TOTP, TOTP enrolment, email code, backup code) share one algorithm (SecondFactorAttempt).
 */
interface CodeVerification extends SelfAuditedCommand
{
    public string $userId { get; }

    public string $code { get; }

    public string $attemptId { get; }
}
