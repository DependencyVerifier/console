<?php

declare(strict_types=1);

namespace Notification\UI\Command;

use Notification\Application\Command\Send\SendDigest;
use Notification\Application\Query\ListDigests;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run it from cron once a day, in the morning (`make notifications-digest`): the daily digests, and on Monday the
 * weekly ones, one email per account, sent by the worker.
 */
#[AsCommand(name: 'app:notification:digest', description: 'Queue the daily digests (and on Monday the weekly ones)')]
final readonly class SendDigestsCommand
{
    public function __construct(private CommandBus $commands, private QueryBus $queries)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $digests = $this->queries->ask(new ListDigests());
        foreach ($digests as $digest) {
            $this->commands->dispatch(new SendDigest($digest->userId, $digest->frequency, $digest->deliveryIds));
        }

        $io->success(\sprintf('%d digests queued.', \count($digests)));

        return 0;
    }
}
