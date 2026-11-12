<?php

declare(strict_types=1);

namespace Exposure\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * The findings of a team are to be computed again (queue `matching`); recorded once until that computation starts,
 * however many changes ask for it.
 */
final readonly class ExposureRefreshRequested implements DomainEvent
{
    public function __construct(public string $teamId)
    {
    }
}
