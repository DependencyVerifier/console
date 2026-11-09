<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * The account is no longer super admin.
 */
final readonly class SuperAdminRevoked implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
