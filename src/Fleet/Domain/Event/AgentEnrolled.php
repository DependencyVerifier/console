<?php

declare(strict_types=1);

namespace Fleet\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * An agent enrolled (pending or approved by its reusable token).
 */
final readonly class AgentEnrolled implements DomainEvent
{
    public function __construct(public string $agentId, public string $teamId)
    {
    }
}
