<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Command\Member\RevokeExtraPermission;
use Shared\Application\Bus\CommandHandler;

final readonly class RevokeExtraPermissionHandler implements CommandHandler
{
    public function __construct(private ExtraPermissionChange $change)
    {
    }

    public function __invoke(RevokeExtraPermission $command): void
    {
        $this->change->apply($command->teamId, $command->projectId, $command->userId, $command->permission, false);
    }
}
