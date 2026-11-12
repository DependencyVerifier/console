<?php

declare(strict_types=1);

namespace Exposure\UI\Command;

use Exposure\Application\Command\Refresh\RequestExposureRefresh;
use Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run it from cron, daily, after `make eol-sync` (`make findings-refresh`): the ends of life come closer every day.
 */
#[AsCommand(name: 'app:exposure:refresh', description: 'Queue the computation of the findings of every inventoried team')]
final readonly class RefreshExposureCommand
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->commands->dispatch(new RequestExposureRefresh());
        $io->success('The findings of every inventoried team are queued for computation.');

        return 0;
    }
}
