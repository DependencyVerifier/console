<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team\Handler;

use Organization\Application\Command\QuotaInput;
use Organization\Application\Command\Team\UpdateTeam;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\TeamId;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class UpdateTeamHandler implements CommandHandler
{
    public function __construct(private OrganizationAccess $access)
    {
    }

    public function __invoke(UpdateTeam $command): void
    {
        $this->access->assertInstance(Permission::TeamQuotasManage);
        $team = $this->access->lockTeam(TeamId::fromString($command->teamId));
        $team->rename($command->name);
        $team->changeQuotas(QuotaInput::teamQuotas($command->quotas));
    }
}
