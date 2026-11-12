<?php

declare(strict_types=1);

namespace Inventory\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * Someone attached an application to an environment, moved it, or detached it.
 */
final readonly class ApplicationPlaced implements DomainEvent
{
    public function __construct(public string $applicationId, public string $teamId)
    {
    }
}
