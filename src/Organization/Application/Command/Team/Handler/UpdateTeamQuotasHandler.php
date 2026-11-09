<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team\Handler;

use Organization\Application\Command\QuotaInput;
use Organization\Application\Command\Team\UpdateTeamQuotas;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\TeamId;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class UpdateTeamQuotasHandler implements CommandHandler
{
    public function __construct(private OrganizationAccess $access)
    {
    }

    public function __invoke(UpdateTeamQuotas $command): void
    {
        $this->access->assertInstance(Permission::TeamQuotasManage);
        $this->access->lockTeam(TeamId::fromString($command->teamId))->changeQuotas(QuotaInput::teamQuotas($command->quotas));
    }
}
