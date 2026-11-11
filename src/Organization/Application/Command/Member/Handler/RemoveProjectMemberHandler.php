<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Command\Member\RemoveProjectMember;
use Organization\Application\Security\OrganizationAccess;
use Organization\Application\Security\RoleAuthority;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\ProjectId;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * A TEAM_GUEST left without project stays a member of the team (removed explicitly). A member of the team
 * (TEAM_MEMBER or above) leaves a project only through a TEAM_ADMIN.
 */
final readonly class RemoveProjectMemberHandler implements CommandHandler
{
    public function __construct(private OrganizationAccess $access, private RoleAuthority $roles, private MemberGrants $grants)
    {
    }

    public function __invoke(RemoveProjectMember $command): void
    {
        $projectId = ProjectId::fromString($command->projectId);
        $project = $this->access->project($projectId);
        $team = $this->access->lockTeam($project->teamId());
        $this->access->assert(Permission::ProjectMembersManage, $project->teamId(), $projectId);
        $userId = Uuid::fromString($command->userId);
        $role = $project->membershipOf($userId)->role();
        $this->roles->assertProjectRemoval($project->teamId(), $projectId, $role, $team->isMember($userId) ? $team->membershipOf($userId)->role() : null);
        $project->removeMember($userId);
        $this->grants->record($project->teamId(), $projectId, $userId, GrantChange::Removed, $role->value);
    }
}
