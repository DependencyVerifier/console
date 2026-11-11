<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Agent;

use Shared\Application\Bus\Query;

/**
 * Whether the team of the agent already holds this snapshot (an upload then answers 200 instead of 201).
 *
 * @implements Query<bool>
 */
final readonly class IsSnapshotStored implements Query
{
    public function __construct(public string $agentId, public string $inventoryId, public string $digest)
    {
    }
}
