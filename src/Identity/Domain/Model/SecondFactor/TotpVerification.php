<?php

declare(strict_types=1);

namespace Identity\Domain\Model\SecondFactor;

use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\SecondFactor;

/**
 * Outcome of a TOTP check and the second factor to keep (unchanged unless accepted).
 */
final readonly class TotpVerification
{
    public function __construct(public CodeVerificationOutcome $outcome, public SecondFactor $next)
    {
    }
}
