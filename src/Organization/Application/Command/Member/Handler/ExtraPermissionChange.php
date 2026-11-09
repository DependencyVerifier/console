<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Security\OrganizationAccess;
use Organization\Application\Security\RoleAuthority;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\PermissionNotGrantable;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\TeamId;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * Grants or revokes an extra permission: on the team membership for team permissions, on the project membership for
 * project permissions (ExtraGrantable: ○ for the role, checked by the aggregate). Granting needs the actor to hold
 * the permission on that scope: no privilege escalation.
 */
final readonly class ExtraPermissionChange
{
    public function __construct(private OrganizationAccess $access, private RoleAuthority $roles, private MemberGrants $grants)
    {
    }

    public function apply(string $teamId, ?string $projectId, string $userId, string $permissionValue, bool $grant): void
    {
        $permission = Permission::tryFrom($permissionValue) ?? throw new PermissionNotGrantable();
        $user = Uuid::fromString($userId);
        $change = $grant ? GrantChange::ExtraGranted : GrantChange::ExtraRevoked;

        if (null !== $projectId) {
            $project = $this->access->project(ProjectId::fromString($projectId));
            if (!$project->teamId()->equals(TeamId::fromString($teamId))) {
                throw new PermissionNotGrantable();
            }

            $this->access->lockTeam($project->teamId());
            $this->access->assert(Permission::ProjectMembersManage, $project->teamId(), $project->id());
            // Same rank rule as changing or removing: never touch a member ranked above the actor.
            $this->roles->assertProjectRole($project->teamId(), $project->id(), $project->membershipOf($user)->role());
            if ($grant && !$this->access->holds($permission, $project->teamId(), $project->id())) {
                throw new PermissionNotGrantable();
            }

            $grant ? $project->grantExtra($user, $permission) : $project->revokeExtra($user, $permission);
            $this->grants->record($project->teamId(), $project->id(), $user, $change, $permission->value);

            return;
        }

        $id = TeamId::fromString($teamId);
        $team = $this->access->lockTeam($id);
        $this->access->assert(Permission::TeamMembersManage, $id);
        $this->roles->assertTeamRole($id, $team->membershipOf($user)->role());
        if ($grant && !$this->access->holds($permission, $id)) {
            throw new PermissionNotGrantable();
        }

        $grant ? $team->grantExtra($user, $permission) : $team->revokeExtra($user, $permission);
        $this->grants->record($id, null, $user, $change, $permission->value);
    }
}
