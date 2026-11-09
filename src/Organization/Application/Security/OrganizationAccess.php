<?php

declare(strict_types=1);

namespace Organization\Application\Security;

use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use Organization\Domain\Repository\ProjectRepository;
use Organization\Domain\Repository\TeamRepository;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * Loading and authorization of every Organization handler: an object outside the actor's scope is a 404, a visible
 * object without the permission a 403. Permissions are checked on the real owner of the object (its team, its
 * project), never on an id taken from the URL.
 */
final readonly class OrganizationAccess
{
    public function __construct(
        private CurrentActor $currentActor,
        private ActorPermissions $permissions,
        private TeamRepository $teams,
        private ProjectRepository $projects,
    ) {
    }

    public function lockTeam(TeamId $id): Team
    {
        $team = $this->teams->lockById($id);
        if (null === $team || !$this->permissions->current()->isTeamMember($id->toString())) {
            throw ResourceNotFound::of('team');
        }

        return $team;
    }

    public function project(ProjectId $id): Project
    {
        $project = $this->projects->find($id);
        if (null === $project || !$this->permissions->current()->seesProject($project->teamId()->toString(), $id->toString())) {
            throw ResourceNotFound::of('project');
        }

        return $project;
    }

    public function assert(Permission $permission, TeamId $teamId, ?ProjectId $projectId = null): void
    {
        if (!$this->permissions->current()->allows($permission, $teamId->toString(), $projectId?->toString())) {
            throw PermissionDenied::for($permission->value);
        }
    }

    public function holds(Permission $permission, TeamId $teamId, ?ProjectId $projectId = null): bool
    {
        return $this->permissions->current()->allows($permission, $teamId->toString(), $projectId?->toString());
    }

    public function projectRole(ProjectId $projectId): ?ProjectRole
    {
        return $this->permissions->current()->projectRole($projectId->toString());
    }

    public function assertInstance(Permission $permission): void
    {
        if (!$this->permissions->current()->isSuperAdmin()) {
            throw PermissionDenied::for($permission->value);
        }
    }

    public function isSuperAdmin(): bool
    {
        return $this->permissions->current()->isSuperAdmin();
    }

    /**
     * The team role of the actor, or null for a super admin acting without membership.
     */
    public function teamRole(TeamId $teamId): ?TeamRole
    {
        return $this->permissions->current()->teamRole($teamId->toString());
    }

    public function actorId(): Uuid
    {
        return $this->currentActor->actor()->userId();
    }

    public function actorIdOrNull(): ?Uuid
    {
        $actor = $this->currentActor->actor();

        return $actor->isUser() ? $actor->userId() : null;
    }
}
