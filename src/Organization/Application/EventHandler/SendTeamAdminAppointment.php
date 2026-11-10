<?php

declare(strict_types=1);

namespace Organization\Application\EventHandler;

use Organization\Application\Port\TeamAdminAppointmentSender;
use Organization\Domain\Event\TeamAdminAppointed;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\FindAccounts;

/**
 * After the commit: the holder learns that they manage the team. An account invited by the same command also gets
 * Identity's setup link.
 */
final readonly class SendTeamAdminAppointment implements EventHandler
{
    public function __construct(private QueryBus $queries, private TeamAdminAppointmentSender $sender)
    {
    }

    public function __invoke(TeamAdminAppointed $event): void
    {
        $account = $this->queries->ask(new FindAccounts([$event->userId]))[$event->userId] ?? null;
        if (null !== $account) {
            $this->sender->send($account->email, $event->teamId, $event->teamName);
        }
    }
}
