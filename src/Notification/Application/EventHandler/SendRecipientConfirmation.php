<?php

declare(strict_types=1);

namespace Notification\Application\EventHandler;

use Notification\Application\Port\RecipientConfirmationSender;
use Notification\Domain\Event\RecipientInvited;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\FindTeamNames;

/**
 * After the commit: the link leaves only for an invitation really stored.
 */
final readonly class SendRecipientConfirmation implements EventHandler
{
    public function __construct(private QueryBus $queries, private RecipientConfirmationSender $sender)
    {
    }

    public function __invoke(RecipientInvited $event): void
    {
        $this->sender->send($event->email, $this->queries->ask(new FindTeamNames([$event->teamId]))[$event->teamId] ?? '', $event->plainToken);
    }
}
