<?php

declare(strict_types=1);

namespace Organization\Application\EventHandler;

use Organization\Application\Command\Member\RestoreMembershipsOfUser;
use Organization\Application\Command\Member\SuspendMembershipsOfUser;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Identity\AccountDeactivated;
use Shared\Application\Contract\Identity\AccountReactivated;

/**
 * Translates Identity's published facts into Organization commands (own transaction, locks and audit). A failure is
 * audited as event.handler.failed and can be replayed from /admin/users ("Re-sync memberships").
 */
final readonly class CascadeAccountStatus implements EventHandler
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(AccountDeactivated|AccountReactivated $event): void
    {
        $this->commands->dispatch($event instanceof AccountDeactivated
            ? new SuspendMembershipsOfUser($event->userId)
            : new RestoreMembershipsOfUser($event->userId));
    }
}
