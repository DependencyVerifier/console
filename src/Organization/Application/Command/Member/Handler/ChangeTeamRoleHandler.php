<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Command\Member\ChangeTeamRole;
use Organization\Application\Security\OrganizationAccess;
use Organization\Application\Security\RoleAuthority;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\RoleNotAssignable;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeTeamRoleHandler implements CommandHandler
{
    public function __construct(private OrganizationAccess $access, private RoleAuthority $roles, private MemberGrants $grants)
    {
    }

    public function __invoke(ChangeTeamRole $command): void
    {
        $teamId = TeamId::fromString($command->teamId);
        $team = $this->access->lockTeam($teamId);
        $this->access->assert(Permission::TeamMembersManage, $teamId);
        $userId = Uuid::fromString($command->userId);
        $role = TeamRole::tryFrom($command->role) ?? throw new RoleNotAssignable();
        $this->roles->assertTeamRole($teamId, $role, $team->membershipOf($userId)->role());
        $team->changeRole($userId, $role);
        $this->grants->record($teamId, null, $userId, GrantChange::RoleChanged, $role->value);
    }
}
