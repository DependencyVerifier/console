<?php

declare(strict_types=1);

namespace Inventory\UI\Command;

use Inventory\Application\Command\Maintenance\MaintainInventory;
use Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run it from cron, hourly (`make inventory-maintain`).
 */
#[AsCommand(name: 'app:inventory:maintain', description: 'Discard stale inventories, purge the history beyond retention, delete unreferenced snapshots')]
final readonly class MaintainInventoryCommand
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $command = new MaintainInventory();
        $this->commands->dispatch($command);
        $report = $command->report;
        $io->success(\sprintf('%d inventories discarded, %d history entries purged, %d snapshots deleted.', $report->discarded, $report->purgedHistory, $report->collectedBlobs));

        return 0;
    }
}
