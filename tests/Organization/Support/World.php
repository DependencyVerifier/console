<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Organization\Application\Security\OrganizationAccess;
use Organization\Application\Security\RoleAuthority;
use Organization\Domain\Authorization\ProjectGrant;
use Organization\Domain\Authorization\TeamGrant;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use Shared\Application\Audit\Actor;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;

/**
 * An Organization test world: in-memory repositories and the actor's permissions.
 */
final class World
{
    public InMemoryTeams $teams;
    public InMemoryProjects $projects;
    public InMemoryInvitations $invitations;
    public InMemorySettings $settings;
    public InMemoryGrants $grants;
    public FixedPermissions $permissions;
    public FixedActor $actor;
    public MockClock $clock;

    public function __construct()
    {
        $this->teams = new InMemoryTeams();
        $this->projects = new InMemoryProjects();
        $this->invitations = new InMemoryInvitations();
        $this->settings = new InMemorySettings();
        $this->grants = new InMemoryGrants();
        $this->permissions = new FixedPermissions();
        $this->actor = FixedActor::user(Teams::ADMIN);
        $this->clock = new MockClock('2026-11-09 08:00:00');
    }

    public function asSuperAdmin(): self
    {
        $this->permissions = FixedPermissions::superAdmin();

        return $this;
    }

    public function asSystem(): self
    {
        $this->actor = new FixedActor(Actor::system());

        return $this;
    }

    public function as(string $userId, ?TeamRole $teamRole, ?ProjectRole $projectRole = null, string $teamId = Teams::TEAM, string $projectId = Teams::PROJECT): self
    {
        $this->actor = FixedActor::user($userId);
        $this->permissions = new FixedPermissions(
            false,
            null === $teamRole ? [] : [new TeamGrant($teamId, $teamRole)],
            null === $projectRole ? [] : [new ProjectGrant($projectId, $teamId, $projectRole)],
        );

        return $this;
    }

    public function access(): OrganizationAccess
    {
        return new OrganizationAccess($this->actor, $this->permissions, $this->teams, $this->projects);
    }

    public function roles(): RoleAuthority
    {
        return new RoleAuthority($this->access());
    }

    public function uuid(string $id): Uuid
    {
        return Uuid::fromString($id);
    }
}
