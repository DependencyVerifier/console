<?php

declare(strict_types=1);

namespace Shared\Application\Security;

/**
 * Rate limiting for the Application layer (never RateLimiterFactory directly). Storage failures are fail-closed:
 * the adapter throws instead of answering "accepted".
 */
interface AttemptThrottle
{
    /**
     * Does not consume. Refused when the window has no token left.
     */
    public function peek(ThrottleName $name, ThrottleSubject $subject): ThrottleDecision;

    public function consume(ThrottleName $name, ThrottleSubject $subject, int $tokens = 1): ThrottleDecision;
}
