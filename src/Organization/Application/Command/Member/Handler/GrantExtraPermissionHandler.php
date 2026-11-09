<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Command\Member\GrantExtraPermission;
use Shared\Application\Bus\CommandHandler;

final readonly class GrantExtraPermissionHandler implements CommandHandler
{
    public function __construct(private ExtraPermissionChange $change)
    {
    }

    public function __invoke(GrantExtraPermission $command): void
    {
        $this->change->apply($command->teamId, $command->projectId, $command->userId, $command->permission, true);
    }
}
