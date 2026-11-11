<?php

declare(strict_types=1);

namespace Fleet\UI\Command;

use Fleet\Application\Command\Maintenance\MaintainFleet;
use Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run it from cron every few minutes (`make fleet-maintain`); each run handles one batch of each kind.
 */
#[AsCommand(name: 'app:fleet:maintain', description: 'Mark silent agents inactive, revoke long inactive ones, expire late orders, purge old nonces')]
final readonly class MaintainFleetCommand
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $command = new MaintainFleet();
        $this->commands->dispatch($command);
        $report = $command->report;
        $io->success(\sprintf(
            '%d agents inactive, %d revoked after 30 days inactive, %d orders expired, %d nonces purged.',
            $report->inactive, $report->revoked, $report->expiredOrders, $report->purgedNonces,
        ));

        return 0;
    }
}
