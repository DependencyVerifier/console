<?php

declare(strict_types=1);

namespace Shared\Application\Security;

/**
 * Answer of a limiter. `justExhausted` is true only for the consumption that brought the remaining tokens to zero
 * (first block of a window), which triggers a single alert.
 */
final readonly class ThrottleDecision
{
    private function __construct(
        public bool $accepted,
        public bool $justExhausted,
        public \DateTimeImmutable $retryAfter,
    ) {
    }

    public static function accepted(\DateTimeImmutable $now): self
    {
        return new self(true, false, $now);
    }

    public static function acceptedAndExhausted(\DateTimeImmutable $retryAfter): self
    {
        return new self(true, true, $retryAfter);
    }

    public static function refused(\DateTimeImmutable $retryAfter): self
    {
        return new self(false, false, $retryAfter);
    }
}
