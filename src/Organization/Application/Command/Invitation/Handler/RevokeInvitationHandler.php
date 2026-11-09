<?php

declare(strict_types=1);

namespace Organization\Application\Command\Invitation\Handler;

use Organization\Application\Command\Invitation\RevokeInvitation;
use Shared\Application\Bus\CommandHandler;

final readonly class RevokeInvitationHandler implements CommandHandler
{
    public function __construct(private InvitationAccess $access)
    {
    }

    public function __invoke(RevokeInvitation $command): void
    {
        $this->access->manage($command->invitationId)->revoke();
    }
}
