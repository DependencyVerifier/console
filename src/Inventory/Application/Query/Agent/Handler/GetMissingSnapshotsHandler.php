<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Agent\Handler;

use Inventory\Application\Agent\InventoryAgents;
use Inventory\Application\Query\Agent\GetMissingSnapshots;
use Inventory\Domain\Repository\SnapshotBlobRepository;
use Shared\Application\Bus\QueryHandler;

final readonly class GetMissingSnapshotsHandler implements QueryHandler
{
    public function __construct(private InventoryAgents $agents, private SnapshotBlobRepository $blobs)
    {
    }

    /**
     * @return list<string>
     */
    public function __invoke(GetMissingSnapshots $query): array
    {
        $run = $this->agents->runOf($this->agents->approved($query->agentId), $query->inventoryId);

        return $run->missingAmong($this->blobs->storedAmong($run->teamId(), $run->manifest()->digests()));
    }
}
