<?php

declare(strict_types=1);

namespace Organization\Application\Security;

use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\RoleNotAssignable;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;

/**
 * Decision 15 (RoleAssignable): an actor grants a role lower than or equal to their own, and never touches a member
 * ranked above them. A TEAM_ADMIN covers every project role of its team; a PROJECT_ADMIN never touches team roles.
 * The super admin is not bound.
 */
final readonly class RoleAuthority
{
    public function __construct(private OrganizationAccess $access)
    {
    }

    public function assertTeamRole(TeamId $teamId, TeamRole $role, ?TeamRole $current = null): void
    {
        if ($this->access->isSuperAdmin()) {
            return;
        }

        $actor = $this->access->teamRole($teamId) ?? throw new RoleNotAssignable();
        if (!$actor->canAssign($role) || (null !== $current && !$actor->canAssign($current))) {
            throw new RoleNotAssignable();
        }
    }

    public function assertProjectRole(TeamId $teamId, ProjectId $projectId, ProjectRole $role, ?ProjectRole $current = null): void
    {
        if ($this->access->isSuperAdmin() || TeamRole::Admin === $this->access->teamRole($teamId)) {
            return;
        }

        $actor = $this->access->projectRole($projectId) ?? throw new RoleNotAssignable();
        if (!$actor->canAssign($role) || (null !== $current && !$actor->canAssign($current))) {
            throw new RoleNotAssignable();
        }
    }
}
