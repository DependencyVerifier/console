<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * The previous address is told (decision 26: no action link), every session being revoked.
 */
final readonly class EmailChanged implements DomainEvent
{
    public function __construct(public string $userId, public string $previousEmail)
    {
    }
}
