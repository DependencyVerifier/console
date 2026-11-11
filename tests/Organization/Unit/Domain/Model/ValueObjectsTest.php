<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Domain\Model;

use Organization\Domain\Model\AlreadyMember;
use Organization\Domain\Model\DuplicateEnvironmentName;
use Organization\Domain\Model\EnvironmentId;
use Organization\Domain\Model\EnvironmentNotEmpty;
use Organization\Domain\Model\ExtraPermissions;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\InstanceSettings;
use Organization\Domain\Model\InvalidName;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationAlreadyPending;
use Organization\Domain\Model\InvitationEmailMismatch;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationNotPending;
use Organization\Domain\Model\LastTeamAdminRequired;
use Organization\Domain\Model\Names;
use Organization\Domain\Model\NotAMember;
use Organization\Domain\Model\PermissionGrant;
use Organization\Domain\Model\PermissionNotGrantable;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectNotEmpty;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\Quota;
use Organization\Domain\Model\QuotaExceeded;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\RoleNotAssignable;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamQuotas;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Organization\Support\Teams;

use function Symfony\Component\String\u;

final class ValueObjectsTest extends TestCase
{
    #[Test]
    public function it_should_wrap_ids(): void
    {
        foreach ([TeamId::class, ProjectId::class, EnvironmentId::class, InvitationId::class] as $class) {
            $uuid = Uuid::v7();
            $id = $class::of($uuid);
            self::assertSame($uuid->toRfc4122(), $id->toString());
            self::assertTrue($id->equals($class::fromString($uuid->toRfc4122())));
            self::assertFalse($id->equals($class::of(Uuid::v7())));
        }
    }

    #[Test]
    public function it_should_order_team_roles(): void
    {
        self::assertSame([4, 3, 2, 1], array_map(static fn (TeamRole $r): int => $r->level(), TeamRole::cases()));
        self::assertTrue(TeamRole::Admin->canAssign(TeamRole::Admin));
        self::assertTrue(TeamRole::Member->canAssign(TeamRole::Member), 'Equal role allowed.');
        self::assertTrue(TeamRole::Member->canAssign(TeamRole::Guest));
        self::assertFalse(TeamRole::Member->canAssign(TeamRole::Admin));
        self::assertFalse(TeamRole::Guest->canAssign(TeamRole::Viewer));
        self::assertTrue(TeamRole::Admin->canAssignProjectRole(ProjectRole::Admin));
        self::assertFalse(TeamRole::Member->canAssignProjectRole(ProjectRole::Viewer));
        self::assertSame(
            [null, ProjectRole::Member, null, null],
            array_map(static fn (TeamRole $r): ?ProjectRole => $r->impliedProjectRole(), TeamRole::cases()),
            'Only a TEAM_MEMBER ranks as a PROJECT_MEMBER (a TEAM_ADMIN covers everything on its own).',
        );
    }

    #[Test]
    public function it_should_order_project_roles(): void
    {
        self::assertSame([3, 2, 1], array_map(static fn (ProjectRole $r): int => $r->level(), ProjectRole::cases()));
        self::assertTrue(ProjectRole::Admin->canAssign(ProjectRole::Admin));
        self::assertTrue(ProjectRole::Member->canAssign(ProjectRole::Viewer));
        self::assertFalse(ProjectRole::Viewer->canAssign(ProjectRole::Member));
    }

    #[Test]
    public function it_should_block_at_the_limit_and_warn_from_eighty_percent(): void
    {
        $quota = Quota::of(10);

        self::assertTrue($quota->allows(9));
        self::assertFalse($quota->allows(10));
        self::assertSame(10, $quota->limit());
        self::assertSame(10, $quota->toStorage());
        self::assertFalse($quota->isUnlimited());
        self::assertSame(0.5, $quota->ratio(5));
        self::assertSame(1.2, $quota->ratio(12), 'Lowered under the usage.');
        self::assertFalse($quota->isWarning(7));
        self::assertTrue($quota->isWarning(8));

        $unlimited = Quota::unlimited();
        self::assertTrue($unlimited->allows(\PHP_INT_MAX));
        self::assertTrue($unlimited->isUnlimited());
        self::assertNull($unlimited->toStorage());
        self::assertSame(0.0, $unlimited->ratio(100));
        self::assertFalse($unlimited->isWarning(100));
        self::assertTrue(Quota::fromStorage(null)->isUnlimited());

        $zero = Quota::of(0);
        self::assertFalse($zero->allows(0));
        self::assertSame(1.0, $zero->ratio(0));
        self::assertSame(\INF, $zero->ratio(1));

        try {
            $unlimited->limit();
            self::fail('No limit.');
        } catch (\LogicException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        Quota::of(-1);
    }

    #[Test]
    public function it_should_hold_the_nine_quotas_with_their_defaults(): void
    {
        $defaults = TeamQuotas::defaults();
        $expected = [10, 3, 25, 25, 50, 5, 3, 90, 60];
        foreach (QuotaName::cases() as $i => $name) {
            self::assertSame($expected[$i], $defaults->get($name)->limit(), $name->value);
            self::assertSame($expected[$i], $name->defaultValue());
        }

        $values = [];
        foreach (QuotaName::cases() as $i => $name) {
            $values[$name->value] = Quota::of($i + 1);
        }

        $quotas = TeamQuotas::of($values);
        foreach (QuotaName::cases() as $i => $name) {
            self::assertSame($i + 1, $quotas->get($name)->limit());
        }

        self::assertEquals($values, $quotas->all());

        $this->expectException(\InvalidArgumentException::class);
        TeamQuotas::of([QuotaName::MaxProjects->value => Quota::of(1)]);
    }

    #[Test]
    public function it_should_keep_default_quotas_for_the_instance(): void
    {
        $settings = InstanceSettings::initial();
        self::assertEquals(TeamQuotas::defaults(), $settings->defaultQuotas());

        $changed = TeamQuotas::of([...TeamQuotas::defaults()->all(), QuotaName::MaxMembers->value => Quota::of(5)]);
        $settings->changeDefaultQuotas($changed);
        self::assertSame(5, $settings->defaultQuotas()->get(QuotaName::MaxMembers)->limit());
    }

    #[Test]
    public function it_should_record_a_permission_grant(): void
    {
        $grant = PermissionGrant::record(TeamId::fromString(Teams::TEAM), null, Teams::uuid(Teams::MEMBER), GrantChange::RoleAssigned, 'team_member', null, Teams::now());

        self::assertSame(GrantChange::RoleAssigned, $grant->change());
        self::assertSame('team_member', $grant->value());
        self::assertTrue($grant->userId()->equals(Teams::uuid(Teams::MEMBER)));
    }

    #[Test]
    public function it_should_name_each_rule(): void
    {
        $rules = [
            'last_team_admin_required' => new LastTeamAdminRequired(),
            'project_not_empty' => new ProjectNotEmpty(),
            'environment_not_empty' => new EnvironmentNotEmpty(),
            'duplicate_environment_name' => new DuplicateEnvironmentName(),
            'invitation_email_mismatch' => new InvitationEmailMismatch(),
            'invitation_already_pending' => new InvitationAlreadyPending(),
            'invitation_not_pending' => new InvitationNotPending(),
            'already_member' => new AlreadyMember(),
            'not_a_member' => new NotAMember(),
            'role_not_assignable' => new RoleNotAssignable(),
            'permission_not_grantable' => new PermissionNotGrantable(),
            'invalid_name' => new InvalidName(),
        ];
        foreach ($rules as $key => $rule) {
            self::assertSame($key, $rule->ruleKey());
        }

        $quota = new QuotaExceeded(QuotaName::MaxProjects, 10);
        self::assertSame('quota', $quota->ruleKey());
        self::assertSame(QuotaName::MaxProjects, $quota->quota);
        self::assertSame(10, $quota->limit);
        self::assertSame('Quota "max_projects" of 10 reached.', $quota->getMessage());
    }

    #[Test]
    public function it_should_bound_display_names(): void
    {
        self::assertSame(u('t')->repeat(120)->toString(), Names::team(u('t')->repeat(120)->toString()));
        self::assertSame(u('p')->repeat(120)->toString(), Names::project(u('p')->repeat(120)->toString()));
        self::assertSame(u('e')->repeat(60)->toString(), Names::environment(u('e')->repeat(60)->toString()));
        foreach ([static fn () => Names::team(u('t')->repeat(121)->toString()), static fn () => Names::project(u('p')->repeat(121)->toString()), static fn () => Names::environment(u('e')->repeat(61)->toString())] as $tooLong) {
            try {
                $tooLong();
                self::fail('Too long.');
            } catch (InvalidName) {
            }
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_should_keep_extra_permissions_as_lists(): void
    {
        self::assertSame([Permission::HostEnroll], ExtraPermissions::decode(['retired.permission', 'host.enroll']));
        self::assertSame(['b', 'c'], ExtraPermissions::without(['host.enroll', 'b', 'c'], Permission::HostEnroll));
        self::assertSame(['a', 'host.enroll'], ExtraPermissions::with(['a'], Permission::HostEnroll));
        self::assertSame(['host.enroll'], ExtraPermissions::with(['host.enroll'], Permission::HostEnroll));
        self::assertSame('jane@example.com', Invitation::normalize(' Jane@Example.COM '));
    }
}
