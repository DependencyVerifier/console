<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Organization\Domain\Authorization\RolePermissions;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * Membership of an account in a project (entity of the Project aggregate).
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_project_membership')]
#[ORM\UniqueConstraint(name: 'organization_project_membership_unique', columns: ['project_id', 'user_id'])]
class ProjectMembership
{
    #[ORM\Column(length: 16, enumType: MembershipStatus::class)]
    private MembershipStatus $status = MembershipStatus::Active;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $extraPermissions = [];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'memberships')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(type: 'uuid')]
        private Uuid $userId,
        #[ORM\Column(length: 16, enumType: ProjectRole::class)]
        private ProjectRole $role,
        #[ORM\Column]
        private \DateTimeImmutable $joinedAt,
    ) {
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function role(): ProjectRole
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return MembershipStatus::Active === $this->status;
    }

    public function status(): MembershipStatus
    {
        return $this->status;
    }

    public function joinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /**
     * @return list<Permission>
     */
    public function extraPermissions(): array
    {
        return ExtraPermissions::decode($this->extraPermissions);
    }

    public function changeRole(ProjectRole $role): void
    {
        $this->role = $role;
        $kept = [];
        foreach ($this->extraPermissions as $value) {
            if (\in_array(Permission::from($value), RolePermissions::grantableToProjectRole($role), true)) {
                $kept[] = $value;
            }
        }

        $this->extraPermissions = $kept;
    }

    public function suspend(): void
    {
        $this->status = MembershipStatus::Suspended;
    }

    public function restore(): void
    {
        $this->status = MembershipStatus::Active;
    }

    public function grantExtra(Permission $permission): void
    {
        $this->extraPermissions = ExtraPermissions::with($this->extraPermissions, $permission);
    }

    public function revokeExtra(Permission $permission): void
    {
        $this->extraPermissions = ExtraPermissions::without($this->extraPermissions, $permission);
    }
}
