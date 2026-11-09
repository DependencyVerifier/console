<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * The account became super admin.
 */
final readonly class SuperAdminGranted implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
