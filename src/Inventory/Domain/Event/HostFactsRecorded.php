<?php

declare(strict_types=1);

namespace Inventory\Domain\Event;

use Inventory\Domain\Model\ObservedHostFacts;
use Shared\Domain\Event\DomainEvent;

/**
 * An inventory recorded the facts of a host, by the agent of its run. Recorded at each processed inventory, changed
 * or not: Fleet catches up on a host whose facts never change (it writes nothing when nothing differs).
 */
final readonly class HostFactsRecorded implements DomainEvent
{
    public function __construct(public string $hostId, public string $teamId, public string $agentId, public ObservedHostFacts $facts)
    {
    }
}
