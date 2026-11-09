<?php

declare(strict_types=1);

namespace Audit\UI\Command;

use Audit\Infrastructure\PartitionMaintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:audit:maintain-partitions', description: 'Create the next audit partitions, move rows out of DEFAULT, drop expired partitions (owner role)')]
final readonly class MaintainPartitionsCommand
{
    public function __construct(private PartitionMaintenance $maintenance)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->maintenance->run();
        $io->success(\sprintf(
            'Partitions ensured: %d. Months moved out of DEFAULT: %s. Dropped: %s.',
            $result['created'],
            [] === $result['moved'] ? 'none' : implode(', ', $result['moved']),
            [] === $result['dropped'] ? 'none' : implode(', ', $result['dropped']),
        ));

        return 0;
    }
}
