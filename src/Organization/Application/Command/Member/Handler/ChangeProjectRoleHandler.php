<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Command\Member\ChangeProjectRole;
use Organization\Application\Security\OrganizationAccess;
use Organization\Application\Security\RoleAuthority;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\RoleNotAssignable;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeProjectRoleHandler implements CommandHandler
{
    public function __construct(private OrganizationAccess $access, private RoleAuthority $roles, private MemberGrants $grants)
    {
    }

    public function __invoke(ChangeProjectRole $command): void
    {
        $projectId = ProjectId::fromString($command->projectId);
        $project = $this->access->project($projectId);
        $this->access->lockTeam($project->teamId());
        $this->access->assert(Permission::ProjectMembersManage, $project->teamId(), $projectId);
        $userId = Uuid::fromString($command->userId);
        $role = ProjectRole::tryFrom($command->role) ?? throw new RoleNotAssignable();
        $this->roles->assertProjectRole($project->teamId(), $projectId, $role, $project->membershipOf($userId)->role());
        $project->changeRole($userId, $role);
        $this->grants->record($project->teamId(), $projectId, $userId, GrantChange::RoleChanged, $role->value);
    }
}
