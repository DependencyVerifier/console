<?php

declare(strict_types=1);

namespace Organization\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * An environment was marked production, or no longer is.
 */
final readonly class EnvironmentProductionChanged implements DomainEvent
{
    public function __construct(public string $teamId, public string $environmentId)
    {
    }
}
