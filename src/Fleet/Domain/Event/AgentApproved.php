<?php

declare(strict_types=1);

namespace Fleet\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * An agent was approved by a human.
 */
final readonly class AgentApproved implements DomainEvent
{
    public function __construct(public string $agentId, public string $teamId)
    {
    }
}
