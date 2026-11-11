<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Agent\Handler;

use Inventory\Application\Agent\InventoryAgents;
use Inventory\Application\Query\Agent\IsSnapshotStored;
use Inventory\Domain\Model\Digest;
use Inventory\Domain\Repository\SnapshotBlobRepository;
use Shared\Application\Bus\QueryHandler;

final readonly class IsSnapshotStoredHandler implements QueryHandler
{
    public function __construct(private InventoryAgents $agents, private SnapshotBlobRepository $blobs)
    {
    }

    public function __invoke(IsSnapshotStored $query): bool
    {
        $run = $this->agents->runOf($this->agents->approved($query->agentId), $query->inventoryId);

        return null !== $this->blobs->find($run->teamId(), Digest::of($query->digest));
    }
}
