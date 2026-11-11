<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Agent\Handler;

use Inventory\Application\Agent\InventoryAgents;
use Inventory\Application\Command\Agent\CompleteInventory;
use Inventory\Domain\Repository\SnapshotBlobRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;

/**
 * Every snapshot of the manifest is stored (409 missing_snapshots with the list otherwise): the inventory is
 * completed, its processing queued by InventoryCompleted. Completing it again changes nothing.
 */
final readonly class CompleteInventoryHandler implements CommandHandler
{
    public function __construct(private InventoryAgents $agents, private SnapshotBlobRepository $blobs, private ClockInterface $clock)
    {
    }

    public function __invoke(CompleteInventory $command): void
    {
        $agent = $this->agents->approved($command->agentId);
        $run = $this->agents->runOf($agent, $command->inventoryId);
        $run->complete($this->blobs->storedAmong($run->teamId(), $run->manifest()->digests()), $this->clock->now());
    }
}
