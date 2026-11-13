<?php

declare(strict_types=1);

namespace Notification\Application\EventHandler;

use Notification\Application\Port\RecipientConfirmationSender;
use Notification\Domain\Event\RecipientAttached;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\FindTeamNames;

/**
 * After the commit: the link leaves only for a recipient really attached, to an account still active.
 */
final readonly class SendRecipientConfirmation implements EventHandler
{
    public function __construct(private QueryBus $queries, private RecipientConfirmationSender $sender)
    {
    }

    public function __invoke(RecipientAttached $event): void
    {
        $account = $this->queries->ask(new FindAccounts([$event->userId]))[$event->userId] ?? null;
        if (true !== $account?->active) {
            return;
        }

        $this->sender->send($account->email, $this->queries->ask(new FindTeamNames([$event->teamId]))[$event->teamId] ?? '', $event->plainToken);
    }
}
