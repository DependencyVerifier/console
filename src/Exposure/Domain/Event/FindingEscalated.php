<?php

declare(strict_types=1);

namespace Exposure\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * An open end of life finding moved to a closer level (90 days, 30 days, past).
 */
final readonly class FindingEscalated implements DomainEvent
{
    public function __construct(public string $findingId, public string $teamId, public string $level)
    {
    }
}
