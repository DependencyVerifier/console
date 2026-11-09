<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * A super admin reset the second factor of this account.
 */
final readonly class SecondFactorReset implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
