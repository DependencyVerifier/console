<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * The account was reactivated (internal fact; Organization consumes the AccountReactivated contract).
 */
final readonly class UserReactivated implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
