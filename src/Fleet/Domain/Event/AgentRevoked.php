<?php

declare(strict_types=1);

namespace Fleet\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * An agent was revoked: it never comes back.
 */
final readonly class AgentRevoked implements DomainEvent
{
    public function __construct(public string $agentId, public string $teamId)
    {
    }
}
