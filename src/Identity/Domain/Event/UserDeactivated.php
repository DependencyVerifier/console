<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * The account was deactivated (internal fact; Organization consumes the AccountDeactivated contract).
 */
final readonly class UserDeactivated implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
