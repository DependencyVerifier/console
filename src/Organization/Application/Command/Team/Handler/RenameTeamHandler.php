<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team\Handler;

use Organization\Application\Command\Team\RenameTeam;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\TeamId;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class RenameTeamHandler implements CommandHandler
{
    public function __construct(private OrganizationAccess $access)
    {
    }

    public function __invoke(RenameTeam $command): void
    {
        $teamId = TeamId::fromString($command->teamId);
        $team = $this->access->lockTeam($teamId);
        $this->access->assert(Permission::TeamEdit, $teamId);
        $team->rename($command->name);
    }
}
