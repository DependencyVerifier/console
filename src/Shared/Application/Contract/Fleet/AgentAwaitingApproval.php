<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Fleet;

use Shared\Domain\Event\DomainEvent;

/**
 * Published language of Fleet: an agent enrolled with a single-use token waits for a human approval.
 */
final readonly class AgentAwaitingApproval implements DomainEvent
{
    public function __construct(public string $agentId, public string $teamId, public string $hostname)
    {
    }
}
