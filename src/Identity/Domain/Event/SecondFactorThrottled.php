<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * The password was right but the second factor failed too often: blocked until `until`. Triggers the alert email.
 */
final readonly class SecondFactorThrottled implements DomainEvent
{
    public function __construct(public string $userId, public \DateTimeImmutable $until)
    {
    }
}
