<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Maintenance\Handler;

use Inventory\Application\Command\Maintenance\MaintainInventory;
use Inventory\Domain\Repository\InventoryRunRepository;
use Inventory\Domain\Repository\SnapshotBlobRepository;
use Inventory\Domain\Repository\SnapshotRecordRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\GetTeamQuota;

final readonly class MaintainInventoryHandler implements CommandHandler
{
    public const string RETENTION_QUOTA = 'snapshot_retention_days';

    public function __construct(
        private InventoryRunRepository $runs,
        private SnapshotRecordRepository $records,
        private SnapshotBlobRepository $blobs,
        private QueryBus $queries,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(MaintainInventory $command): void
    {
        $now = $this->clock->now();
        $report = $command->report;
        foreach ($this->runs->openReceivedBefore($now->modify('-1 hour'), $command->batch) as $run) {
            if ($run->discardIfStale($now)) {
                ++$report->discarded;
            }
        }

        foreach ($this->records->teams() as $team) {
            $days = $this->queries->ask(new GetTeamQuota($team->toRfc4122(), self::RETENTION_QUOTA));
            if (null !== $days) {
                $report->purgedHistory += $this->records->purgeBefore($team, $now->modify(\sprintf('-%d days', $days)));
            }
        }

        $report->collectedBlobs = $this->blobs->deleteUnreferenced($command->batch);
    }
}
