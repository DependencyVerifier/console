<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Domain\Model;

use Organization\Domain\Event\TeamCreated;
use Organization\Domain\Model\AlreadyMember;
use Organization\Domain\Model\InvalidName;
use Organization\Domain\Model\LastTeamAdminRequired;
use Organization\Domain\Model\MembershipStatus;
use Organization\Domain\Model\NotAMember;
use Organization\Domain\Model\PermissionNotGrantable;
use Organization\Domain\Model\Quota;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamQuotas;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Authorization\Permission;
use Tests\Organization\Support\Teams;

final class TeamTest extends TestCase
{
    #[Test]
    public function it_should_create_a_team_with_a_copy_of_the_default_quotas(): void
    {
        $team = Team::create(TeamId::fromString(Teams::TEAM), '  Platform  ', 'platform', TeamQuotas::defaults(), Teams::now());

        self::assertSame(Teams::TEAM, $team->id()->toString());
        self::assertSame('Platform', $team->name());
        self::assertSame('platform', $team->slug());
        self::assertEquals(Teams::now(), $team->createdAt());
        self::assertSame(10, $team->quotas()->get(QuotaName::MaxProjects)->limit());
        self::assertFalse($team->isAdminEstablished());
        self::assertSame([], $team->memberships());
        self::assertEquals([new TeamCreated(Teams::TEAM)], $team->releaseEvents());
    }

    #[Test]
    public function it_should_rename_and_change_quotas(): void
    {
        $team = Teams::team();
        $team->rename(' Payments ');
        $quotas = TeamQuotas::of([...TeamQuotas::defaults()->all(), QuotaName::MaxProjects->value => Quota::unlimited()]);
        $team->changeQuotas($quotas);

        self::assertSame('Payments', $team->name());
        self::assertTrue($team->quotas()->get(QuotaName::MaxProjects)->isUnlimited());

        $this->expectException(InvalidName::class);
        $team->rename('   ');
    }

    #[Test]
    public function it_should_admit_once_and_establish_the_admin(): void
    {
        $team = Teams::team();
        $member = $team->admit(Teams::uuid(Teams::MEMBER), TeamRole::Member, Teams::now());

        self::assertFalse($team->isAdminEstablished(), 'Tolerated without admin until one is admitted.');
        self::assertSame(TeamRole::Member, $member->role());
        self::assertSame($team, $member->team());
        self::assertEquals(Teams::now(), $member->joinedAt());
        self::assertTrue($member->userId()->equals(Teams::uuid(Teams::MEMBER)));
        self::assertSame(MembershipStatus::Active, $member->status());
        self::assertTrue($team->isMember(Teams::uuid(Teams::MEMBER)));
        self::assertSame($member, $team->membershipOf(Teams::uuid(Teams::MEMBER)));

        $team->admit(Teams::uuid(Teams::ADMIN), TeamRole::Admin, Teams::now());
        self::assertTrue($team->isAdminEstablished());
        self::assertSame(2, $team->activeMemberCount());
        self::assertCount(2, $team->memberships());

        $this->expectException(AlreadyMember::class);
        $team->admit(Teams::uuid(Teams::MEMBER), TeamRole::Viewer, Teams::now());
    }

    #[Test]
    public function it_should_keep_a_team_admin_once_established(): void
    {
        $team = Teams::withAdmin();

        foreach ([static fn () => $team->changeRole(Teams::uuid(Teams::ADMIN), TeamRole::Member), static fn () => $team->removeMember(Teams::uuid(Teams::ADMIN))] as $gesture) {
            try {
                $gesture();
                self::fail('Last admin.');
            } catch (LastTeamAdminRequired) {
            }
        }

        self::assertSame(TeamRole::Admin, $team->membershipOf(Teams::uuid(Teams::ADMIN))->role(), 'Checked before any change.');
        self::assertTrue($team->isMember(Teams::uuid(Teams::ADMIN)));
        $team->admit(Teams::uuid(Teams::MEMBER), TeamRole::Admin, Teams::now());
        $team->changeRole(Teams::uuid(Teams::ADMIN), TeamRole::Member);
        self::assertSame(TeamRole::Member, $team->membershipOf(Teams::uuid(Teams::ADMIN))->role());
        $team->removeMember(Teams::uuid(Teams::ADMIN));
        self::assertFalse($team->isMember(Teams::uuid(Teams::ADMIN)));
    }

    #[Test]
    public function it_should_not_count_a_suspended_admin(): void
    {
        $team = Teams::withAdmin();
        $team->admit(Teams::uuid(Teams::MEMBER), TeamRole::Admin, Teams::now());
        $team->suspendMember(Teams::uuid(Teams::MEMBER));

        self::assertSame(1, $team->activeMemberCount());
        $this->expectException(LastTeamAdminRequired::class);
        $team->changeRole(Teams::uuid(Teams::ADMIN), TeamRole::Viewer);
    }

    #[Test]
    public function it_should_suspend_even_the_last_admin_and_restore_idempotently(): void
    {
        $team = Teams::withAdmin();
        $team->suspendMember(Teams::uuid(Teams::ADMIN));
        $team->suspendMember(Teams::uuid(Teams::ADMIN));
        $team->suspendMember(Teams::uuid(Teams::MEMBER));

        self::assertFalse($team->membershipOf(Teams::uuid(Teams::ADMIN))->isActive());
        self::assertSame(0, $team->activeMemberCount());

        $team->restoreMember(Teams::uuid(Teams::ADMIN));
        $team->restoreMember(Teams::uuid(Teams::MEMBER));
        self::assertTrue($team->membershipOf(Teams::uuid(Teams::ADMIN))->isActive());
    }

    #[Test]
    public function it_should_allow_role_changes_and_removals_before_any_admin(): void
    {
        $team = Teams::team();
        $team->admit(Teams::uuid(Teams::MEMBER), TeamRole::Member, Teams::now());
        $team->changeRole(Teams::uuid(Teams::MEMBER), TeamRole::Viewer);
        $team->removeMember(Teams::uuid(Teams::MEMBER));

        self::assertFalse($team->isMember(Teams::uuid(Teams::MEMBER)));
    }

    #[Test]
    public function it_should_establish_the_admin_when_a_member_is_promoted(): void
    {
        $team = Teams::team();
        $team->admit(Teams::uuid(Teams::MEMBER), TeamRole::Member, Teams::now());
        $team->changeRole(Teams::uuid(Teams::MEMBER), TeamRole::Admin);

        self::assertTrue($team->isAdminEstablished());
    }

    #[Test]
    public function it_should_let_an_admin_be_demoted_before_the_team_has_one_established(): void
    {
        $team = Teams::withAdmin();
        new \ReflectionProperty(Team::class, 'adminEstablished')->setValue($team, false);

        $team->changeRole(Teams::uuid(Teams::ADMIN), TeamRole::Member);

        self::assertSame(TeamRole::Member, $team->membershipOf(Teams::uuid(Teams::ADMIN))->role());
    }

    #[Test]
    public function it_should_grant_only_circle_permissions_of_the_team_scope(): void
    {
        $team = Teams::withAdmin();
        $member = $team->admit(Teams::uuid(Teams::MEMBER), TeamRole::Member, Teams::now());
        $team->grantExtra(Teams::uuid(Teams::MEMBER), Permission::HostEnroll);
        $team->grantExtra(Teams::uuid(Teams::MEMBER), Permission::HostEnroll);
        $team->grantExtra(Teams::uuid(Teams::MEMBER), Permission::ProjectCreate);

        self::assertSame([Permission::HostEnroll, Permission::ProjectCreate], $member->extraPermissions());

        $team->revokeExtra(Teams::uuid(Teams::MEMBER), Permission::HostEnroll);
        self::assertSame([Permission::ProjectCreate], $member->extraPermissions());

        foreach ([Permission::TeamMembersManage, Permission::FindingAcceptRisk, Permission::InstanceAuditView] as $refused) {
            try {
                $team->grantExtra(Teams::uuid(Teams::MEMBER), $refused);
                self::fail($refused->value);
            } catch (PermissionNotGrantable) {
            }
        }
    }

    #[Test]
    public function it_should_drop_extras_the_new_role_cannot_hold(): void
    {
        $team = Teams::withAdmin();
        $member = $team->admit(Teams::uuid(Teams::MEMBER), TeamRole::Member, Teams::now());
        $team->grantExtra(Teams::uuid(Teams::MEMBER), Permission::TeamAuditView);
        $team->grantExtra(Teams::uuid(Teams::MEMBER), Permission::HostEnroll);

        $team->changeRole(Teams::uuid(Teams::MEMBER), TeamRole::Viewer);

        self::assertSame([Permission::TeamAuditView], $member->extraPermissions());
    }

    #[Test]
    public function it_should_refuse_an_unknown_member(): void
    {
        $team = Teams::withAdmin();
        foreach ([
            static fn () => $team->membershipOf(Teams::uuid(Teams::GUEST)),
            static fn () => $team->changeRole(Teams::uuid(Teams::GUEST), TeamRole::Viewer),
            static fn () => $team->removeMember(Teams::uuid(Teams::GUEST)),
            static fn () => $team->grantExtra(Teams::uuid(Teams::GUEST), Permission::HostEnroll),
            static fn () => $team->revokeExtra(Teams::uuid(Teams::GUEST), Permission::HostEnroll),
        ] as $gesture) {
            try {
                $gesture();
                self::fail('Not a member.');
            } catch (NotAMember $refused) {
                self::assertSame('not_a_member', $refused->ruleKey());
            }
        }
    }

    #[Test]
    public function it_should_keep_lists_and_stored_extras_reindexed(): void
    {
        $team = Teams::withAdmin();
        $member = $team->admit(Teams::uuid(Teams::MEMBER), TeamRole::Member, Teams::now());
        $team->grantExtra(Teams::uuid(Teams::MEMBER), Permission::HostEnroll);
        $team->grantExtra(Teams::uuid(Teams::MEMBER), Permission::TeamAuditView);
        $team->changeRole(Teams::uuid(Teams::MEMBER), TeamRole::Viewer);

        self::assertSame(['team.audit.view'], new \ReflectionProperty($member, 'extraPermissions')->getValue($member), 'A JSON list, not an object.');

        $team->changeRole(Teams::uuid(Teams::ADMIN), TeamRole::Admin);
        self::assertSame(TeamRole::Admin, $team->membershipOf(Teams::uuid(Teams::ADMIN))->role(), 'Admin to admin is no demotion.');

        $team->admit(Teams::uuid(Teams::GUEST), TeamRole::Admin, Teams::now());
        $team->removeMember(Teams::uuid(Teams::ADMIN));
        self::assertSame([$member, $team->membershipOf(Teams::uuid(Teams::GUEST))], $team->memberships());
    }
}
