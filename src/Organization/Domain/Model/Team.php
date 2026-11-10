<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Organization\Domain\Authorization\RolePermissions;
use Organization\Domain\Event\TeamAdminAppointed;
use Organization\Domain\Event\TeamCreated;
use Shared\Domain\Authorization\Permission;
use Shared\Domain\Authorization\PermissionScope;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Team aggregate, root of its memberships and the consistency boundary of everything a team owns (memberships,
 * invitations, projects, environments, quotas): commands load it locked (FOR UPDATE) first.
 *
 * `adminEstablished` turns on at the first TEAM_ADMIN admitted: from then on the team keeps at least one active
 * TEAM_ADMIN (KeepsATeamAdmin), except for a suspension following an account deactivation.
 *
 * Deviation from the 4-attribute rule, recorded in CLAUDE.md: collection and event trait.
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_team')]
class Team implements RecordsEvents
{
    use EventRecording;

    #[ORM\Column]
    private bool $adminEstablished = false;

    /** @var Collection<int, TeamMembership> */
    #[ORM\OneToMany(targetEntity: TeamMembership::class, mappedBy: 'team', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(length: 120)]
        private string $name,
        #[ORM\Column(length: 140, unique: true)]
        private string $slug,
        #[ORM\Embedded(class: TeamQuotas::class, columnPrefix: 'quota_')]
        private TeamQuotas $quotas,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->memberships = new ArrayCollection();
    }

    public static function create(TeamId $id, string $name, string $slug, TeamQuotas $defaults, \DateTimeImmutable $now): self
    {
        $team = new self($id->value, Names::team($name), $slug, $defaults, $now);
        $team->recordThat(new TeamCreated($id->toString()));

        return $team;
    }

    public function id(): TeamId
    {
        return TeamId::of($this->id);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function quotas(): TeamQuotas
    {
        return $this->quotas;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAdminEstablished(): bool
    {
        return $this->adminEstablished;
    }

    public function rename(string $name): void
    {
        $this->name = Names::team($name);
    }

    public function changeQuotas(TeamQuotas $quotas): void
    {
        $this->quotas = $quotas;
    }

    // ---------------------------------------------------------------- memberships

    public function isMember(Uuid $userId): bool
    {
        return null !== $this->find($userId);
    }

    public function membershipOf(Uuid $userId): TeamMembership
    {
        return $this->find($userId) ?? throw new NotAMember();
    }

    /**
     * Active memberships (counted by maxMembers together with the pending invitations).
     */
    public function activeMemberCount(): int
    {
        return $this->memberships->filter(static fn (TeamMembership $m): bool => $m->isActive())->count();
    }

    /**
     * @return list<TeamMembership>
     */
    public function memberships(): array
    {
        return array_values($this->memberships->toArray());
    }

    public function admit(Uuid $userId, TeamRole $role, \DateTimeImmutable $now): TeamMembership
    {
        if ($this->isMember($userId)) {
            throw new AlreadyMember();
        }

        $membership = new TeamMembership(Uuid::v7(), $this, $userId, $role, $now);
        $this->memberships->add($membership);
        if (TeamRole::Admin === $role) {
            $this->adminEstablished = true;
        }

        return $membership;
    }

    /**
     * The first TEAM_ADMIN, chosen by the super admin who creates the team: admitted at once, then told by email.
     */
    public function appointFirstAdmin(Uuid $userId, \DateTimeImmutable $now): TeamMembership
    {
        $membership = $this->admit($userId, TeamRole::Admin, $now);
        $this->recordThat(new TeamAdminAppointed($this->id->toRfc4122(), $userId->toRfc4122(), $this->name));

        return $membership;
    }

    public function changeRole(Uuid $userId, TeamRole $role): void
    {
        $membership = $this->membershipOf($userId);
        if (TeamRole::Admin === $membership->role() && TeamRole::Admin !== $role) {
            $this->assertAnotherAdminThan($membership);
        }

        $membership->changeRole($role);
        if (TeamRole::Admin === $role) {
            $this->adminEstablished = true;
        }
    }

    public function removeMember(Uuid $userId): void
    {
        $membership = $this->membershipOf($userId);
        if (TeamRole::Admin === $membership->role()) {
            $this->assertAnotherAdminThan($membership);
        }

        $this->memberships->removeElement($membership);
    }

    /**
     * Idempotent; exempt from KeepsATeamAdmin (the super admin may deactivate the last admin of a team).
     */
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
        if (PermissionScope::Team !== $permission->scope() || !\in_array($permission, RolePermissions::grantableToTeamRole($membership->role()), true)) {
            throw new PermissionNotGrantable();
        }

        $membership->grantExtra($permission);
    }

    public function revokeExtra(Uuid $userId, Permission $permission): void
    {
        $this->membershipOf($userId)->revokeExtra($permission);
    }

    /**
     * KeepsATeamAdmin, checked before any change: another active TEAM_ADMIN remains once $leaving is gone.
     */
    private function assertAnotherAdminThan(TeamMembership $leaving): void
    {
        if (!$this->adminEstablished) {
            return;
        }

        foreach ($this->memberships as $membership) {
            if ($membership !== $leaving && TeamRole::Admin === $membership->role() && $membership->isActive()) {
                return;
            }
        }

        throw new LastTeamAdminRequired();
    }

    private function find(Uuid $userId): ?TeamMembership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->userId()->equals($userId)) {
                return $membership;
            }
        }

        return null;
    }
}
