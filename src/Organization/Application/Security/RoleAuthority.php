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
 * ranked above them. A TEAM_ADMIN covers every project role of its team; a TEAM_MEMBER ranks as a PROJECT_MEMBER in
 * its team's projects; a PROJECT_ADMIN never touches team roles nor removes a member of the team from a project.
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
        $teamRole = $this->access->teamRole($teamId);
        if ($this->access->isSuperAdmin() || TeamRole::Admin === $teamRole) {
            return;
        }

        $actor = self::higher($this->access->projectRole($projectId), $teamRole?->impliedProjectRole()) ?? throw new RoleNotAssignable();
        if (!$actor->canAssign($role) || (null !== $current && !$actor->canAssign($current))) {
            throw new RoleNotAssignable();
        }
    }

    /**
     * Removing someone from a project: the rank rule, and a member of the team (TEAM_MEMBER or above) is removed from
     * a project by a TEAM_ADMIN only, never by a PROJECT_ADMIN (decided 2026-11-11).
     */
    public function assertProjectRemoval(TeamId $teamId, ProjectId $projectId, ProjectRole $current, ?TeamRole $memberTeamRole): void
    {
        if ($this->access->isSuperAdmin() || TeamRole::Admin === $this->access->teamRole($teamId)) {
            return;
        }

        if (TeamRole::Admin === $memberTeamRole || TeamRole::Member === $memberTeamRole) {
            throw new RoleNotAssignable();
        }

        $this->assertProjectRole($teamId, $projectId, $current);
    }

    private static function higher(?ProjectRole $a, ?ProjectRole $b): ?ProjectRole
    {
        if (null === $a || null === $b) {
            // @infection-ignore Coalesce
            return $a ?? $b;
        }

        return $a->canAssign($b) ? $a : $b;
    }
}
