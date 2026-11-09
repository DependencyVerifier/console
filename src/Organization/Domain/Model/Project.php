<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Organization\Domain\Authorization\RolePermissions;
use Shared\Domain\Authorization\Permission;
use Shared\Domain\Authorization\PermissionScope;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * Project aggregate, root of its memberships and environments. Belongs to a team, whose lock serializes the quota
 * checks; its slug is unique per team. A project is empty when it has no environment (this step).
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_project')]
#[ORM\UniqueConstraint(name: 'organization_project_slug', columns: ['team_id', 'slug'])]
class Project implements RecordsEvents
{
    use EventRecording;

    /** @var Collection<int, ProjectMembership> */
    #[ORM\OneToMany(targetEntity: ProjectMembership::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    /** @var Collection<int, Environment> */
    #[ORM\OneToMany(targetEntity: Environment::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $environments;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(length: 120)]
        private string $name,
        #[ORM\Column(length: 140)]
        private string $slug,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->memberships = new ArrayCollection();
        $this->environments = new ArrayCollection();
    }

    /**
     * The creator becomes PROJECT_ADMIN unless their team role already covers it (TEAM_ADMIN), or they act as super
     * admin without any team role (null).
     */
    public static function create(ProjectId $id, TeamId $teamId, string $name, string $slug, Uuid $creatorId, ?TeamRole $creatorRole, \DateTimeImmutable $now): self
    {
        $project = new self($id->value, $teamId->value, Names::project($name), $slug, $now);
        if (null !== $creatorRole && TeamRole::Admin !== $creatorRole) {
            $project->memberships->add(new ProjectMembership(Uuid::v7(), $project, $creatorId, ProjectRole::Admin, $now));
        }

        return $project;
    }

    public function id(): ProjectId
    {
        return ProjectId::of($this->id);
    }

    public function teamId(): TeamId
    {
        return TeamId::of($this->teamId);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rename(string $name): void
    {
        $this->name = Names::project($name);
    }

    /**
     * Refused while the project has content (environments, or a content probe of another context).
     */
    public function assertDeletable(): void
    {
        if (!$this->environments->isEmpty()) {
            throw new ProjectNotEmpty();
        }
    }

    // ---------------------------------------------------------------- memberships

    public function isMember(Uuid $userId): bool
    {
        return null !== $this->find($userId);
    }

    public function membershipOf(Uuid $userId): ProjectMembership
    {
        return $this->find($userId) ?? throw new NotAMember();
    }

    /**
     * @return list<ProjectMembership>
     */
    public function memberships(): array
    {
        return array_values($this->memberships->toArray());
    }

    public function admit(Uuid $userId, ProjectRole $role, \DateTimeImmutable $now): ProjectMembership
    {
        if ($this->isMember($userId)) {
            throw new AlreadyMember();
        }

        $membership = new ProjectMembership(Uuid::v7(), $this, $userId, $role, $now);
        $this->memberships->add($membership);

        return $membership;
    }

    public function changeRole(Uuid $userId, ProjectRole $role): void
    {
        $this->membershipOf($userId)->changeRole($role);
    }

    public function removeMember(Uuid $userId): void
    {
        $this->memberships->removeElement($this->membershipOf($userId));
    }

    public function suspendMember(Uuid $userId): void
    {
        $this->find($userId)?->suspend();
    }

    public function restoreMember(Uuid $userId): void
    {
        $this->find($userId)?->restore();
    }

    public function grantExtra(Uuid $userId, Permission $permission): void
    {
        $membership = $this->membershipOf($userId);
        if (PermissionScope::Project !== $permission->scope() || !\in_array($permission, RolePermissions::grantableToProjectRole($membership->role()), true)) {
            throw new PermissionNotGrantable();
        }

        $membership->grantExtra($permission);
    }

    public function revokeExtra(Uuid $userId, Permission $permission): void
    {
        $this->membershipOf($userId)->revokeExtra($permission);
    }

    // ---------------------------------------------------------------- environments

    /**
     * @return list<Environment>
     */
    public function environments(): array
    {
        return array_values($this->environments->toArray());
    }

    public function environmentCount(): int
    {
        return $this->environments->count();
    }

    public function environment(EnvironmentId $id): Environment
    {
        foreach ($this->environments as $environment) {
            if ($environment->id()->equals($id)) {
                return $environment;
            }
        }

        throw new EnvironmentNotFound();
    }

    public function addEnvironment(EnvironmentId $id, string $name, bool $production): Environment
    {
        $name = Names::environment($name);
        $this->assertUniqueName($name, null);
        $environment = new Environment($id->value, $this, $name, $production);
        $this->environments->add($environment);

        return $environment;
    }

    public function updateEnvironment(EnvironmentId $id, string $name, bool $production): void
    {
        $name = Names::environment($name);
        $environment = $this->environment($id);
        $this->assertUniqueName($name, $environment);
        $environment->update($name, $production);
    }

    public function removeEnvironment(EnvironmentId $id): void
    {
        $this->environments->removeElement($this->environment($id));
    }

    private function assertUniqueName(string $name, ?Environment $except): void
    {
        foreach ($this->environments as $environment) {
            if ($environment !== $except && u($environment->name())->lower()->equalsTo(u($name)->lower()->toString())) {
                throw new DuplicateEnvironmentName();
            }
        }
    }

    private function find(Uuid $userId): ?ProjectMembership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->userId()->equals($userId)) {
                return $membership;
            }
        }

        return null;
    }
}
