<?php

declare(strict_types=1);

namespace Organization\Domain\Authorization;

use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use Shared\Domain\Authorization\Permission;
use Shared\Domain\Authorization\PermissionScope;

/**
 * Effective permissions of one actor (docs/cadrage.md §7bis): on a project, team role + team extras + project role +
 * project extras; the super admin holds everything. Instance permissions are Identity's (never answered here).
 */
final readonly class EffectivePermissions
{
    /**
     * @param array<string, TeamGrant>    $teams    by team id
     * @param array<string, ProjectGrant> $projects by project id
     */
    private function __construct(private bool $superAdmin, private array $teams, private array $projects)
    {
    }

    /**
     * @param list<TeamGrant>    $teams
     * @param list<ProjectGrant> $projects
     */
    public static function resolve(bool $superAdmin, array $teams, array $projects): self
    {
        $byTeam = [];
        foreach ($teams as $grant) {
            $byTeam[$grant->teamId] = $grant;
        }

        $byProject = [];
        foreach ($projects as $grant) {
            $byProject[$grant->projectId] = $grant;
        }

        return new self($superAdmin, $byTeam, $byProject);
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function allows(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
    {
        if ($this->superAdmin) {
            return true;
        }

        return match ($permission->scope()) {
            PermissionScope::Instance => false,
            PermissionScope::Team => null !== $teamId && \in_array($permission, $this->teamPermissions($teamId), true),
            PermissionScope::Project => null !== $teamId && null !== $projectId && \in_array($permission, $this->projectPermissions($teamId, $projectId), true),
        };
    }

    public function teamRole(string $teamId): ?TeamRole
    {
        return ($this->teams[$teamId] ?? null)?->role;
    }

    public function projectRole(string $projectId): ?ProjectRole
    {
        return ($this->projects[$projectId] ?? null)?->role;
    }

    public function isTeamMember(string $teamId): bool
    {
        return $this->superAdmin || isset($this->teams[$teamId]);
    }

    /**
     * Every project of the team is visible (any team role but TEAM_GUEST, or the super admin).
     */
    public function seesAllProjectsOf(string $teamId): bool
    {
        $role = $this->teamRole($teamId);

        return $this->superAdmin || (null !== $role && TeamRole::Guest !== $role);
    }

    public function seesProject(string $teamId, string $projectId): bool
    {
        return $this->seesAllProjectsOf($teamId) || ($this->projects[$projectId] ?? null)?->teamId === $teamId;
    }

    /**
     * @return list<string>
     */
    public function projectIdsOf(string $teamId): array
    {
        return array_values(array_map(
            static fn (ProjectGrant $grant): string => $grant->projectId,
            array_filter($this->projects, static fn (ProjectGrant $grant): bool => $grant->teamId === $teamId),
        ));
    }

    /**
     * @return list<string>
     */
    public function teamIds(): array
    {
        return array_keys($this->teams);
    }

    /**
     * @return list<Permission>
     */
    private function teamPermissions(string $teamId): array
    {
        $grant = $this->teams[$teamId] ?? null;

        return null === $grant ? [] : [...RolePermissions::defaultsOfTeamRole($grant->role), ...$grant->extras];
    }

    /**
     * @return list<Permission>
     */
    private function projectPermissions(string $teamId, string $projectId): array
    {
        $project = $this->projects[$projectId] ?? null;
        if (null !== $project && $project->teamId !== $teamId) {
            return [];
        }

        $permissions = $this->teamPermissions($teamId);
        if (null !== $project) {
            $permissions = [...$permissions, ...RolePermissions::defaultsOfProjectRole($project->role), ...$project->extras];
        }

        return $permissions;
    }
}
