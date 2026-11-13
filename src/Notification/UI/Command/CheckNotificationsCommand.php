<?php

declare(strict_types=1);

namespace Notification\UI\Command;

use Notification\Application\Command\Notify\CheckNotifications;
use Notification\Application\Command\Send\SendDelivery;
use Notification\Application\Query\ListDueDeliveries;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run it from cron every 5 minutes (`make notifications-check`): the silent agents and the expiring acceptances are
 * told (once each), the immediate deliveries due again are queued.
 */
#[AsCommand(name: 'app:notification:check', description: 'Notify the silent agents and the expiring risk acceptances, queue the deliveries due again')]
final readonly class CheckNotificationsCommand
{
    public function __construct(private CommandBus $commands, private QueryBus $queries)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->commands->dispatch(new CheckNotifications());
        $due = $this->queries->ask(new ListDueDeliveries());
        foreach ($due as $deliveryId) {
            $this->commands->dispatch(new SendDelivery($deliveryId));
        }

        $io->success(\sprintf('Checked; %d deliveries queued again.', \count($due)));

        return 0;
    }
}
