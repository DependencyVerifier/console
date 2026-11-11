<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Command\Member\ChangeExtraPermissions;
use Organization\Application\Command\Member\ChangeProjectRole;
use Organization\Application\Command\Member\ChangeTeamRole;
use Organization\Application\Command\Member\GrantExtraPermission;
use Organization\Application\Command\Member\Handler\ChangeExtraPermissionsHandler;
use Organization\Application\Command\Member\Handler\ChangeProjectRoleHandler;
use Organization\Application\Command\Member\Handler\ChangeTeamRoleHandler;
use Organization\Application\Command\Member\Handler\ExtraPermissionChange;
use Organization\Application\Command\Member\Handler\GrantExtraPermissionHandler;
use Organization\Application\Command\Member\Handler\MemberGrants;
use Organization\Application\Command\Member\Handler\MembershipCascade;
use Organization\Application\Command\Member\Handler\RemoveProjectMemberHandler;
use Organization\Application\Command\Member\Handler\RemoveTeamMemberHandler;
use Organization\Application\Command\Member\Handler\RestoreMembershipsOfUserHandler;
use Organization\Application\Command\Member\Handler\RevokeExtraPermissionHandler;
use Organization\Application\Command\Member\Handler\SuspendMembershipsOfUserHandler;
use Organization\Application\Command\Member\RemoveProjectMember;
use Organization\Application\Command\Member\RemoveTeamMember;
use Organization\Application\Command\Member\RestoreMembershipsOfUser;
use Organization\Application\Command\Member\RevokeExtraPermission;
use Organization\Application\Command\Member\SuspendMembershipsOfUser;
use Organization\Application\EventHandler\CascadeAccountStatus;
use Organization\Application\EventHandler\SendInvitation;
use Organization\Application\Port\InvitationSender;
use Organization\Application\Query\Member\GetMemberPermissions;
use Organization\Application\Query\Member\Handler\GetMemberPermissionsHandler;
use Organization\Application\Query\View\PermissionChoice;
use Organization\Domain\Authorization\ProjectGrant;
use Organization\Domain\Authorization\RolePermissions;
use Organization\Domain\Authorization\TeamGrant;
use Organization\Domain\Event\InvitationIssued;
use Organization\Domain\Event\InvitationReissued;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\PermissionNotGrantable;
use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\RoleNotAssignable;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Identity\AccountDeactivated;
use Shared\Application\Contract\Identity\AccountReactivated;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Organization\Support\FixedPermissions;
use Tests\Organization\Support\RecordingCommands;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

final class MemberHandlersTest extends TestCase
{
    private Team $team;
    private Project $project;

    #[Test]
    public function it_should_change_a_team_role_with_its_grant_history(): void
    {
        $world = $this->world();
        new ChangeTeamRoleHandler($world->access(), $world->roles(), $this->grants($world))(new ChangeTeamRole(Teams::TEAM, Teams::MEMBER, 'team_viewer'));

        self::assertSame(TeamRole::Viewer, $this->team->membershipOf(Uuid::fromString(Teams::MEMBER))->role());
        self::assertSame(GrantChange::RoleChanged, $world->grants->grants[0]->change());
        self::assertSame('team_viewer', $world->grants->grants[0]->value());

        foreach ([['team_ruler', RoleNotAssignable::class]] as [$role, $refusal]) {
            try {
                new ChangeTeamRoleHandler($world->access(), $world->roles(), $this->grants($world))(new ChangeTeamRole(Teams::TEAM, Teams::MEMBER, $role));
                self::fail($role);
            } catch (RoleNotAssignable) {
            }
        }

        $world->as(Teams::MEMBER, TeamRole::Member);
        $this->expectException(PermissionDenied::class);
        new ChangeTeamRoleHandler($world->access(), $world->roles(), $this->grants($world))(new ChangeTeamRole(Teams::TEAM, Teams::GUEST, 'team_viewer'));
    }

    #[Test]
    public function it_should_never_touch_a_member_ranked_above_the_actor(): void
    {
        $world = $this->world();
        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Member, [Permission::TeamMembersManage])]);

        $this->expectException(RoleNotAssignable::class);
        new ChangeTeamRoleHandler($world->access(), $world->roles(), $this->grants($world))(new ChangeTeamRole(Teams::TEAM, Teams::ADMIN, 'team_member'));
    }

    #[Test]
    public function it_should_change_a_project_role(): void
    {
        $world = $this->world();
        new ChangeProjectRoleHandler($world->access(), $world->roles(), $this->grants($world))(new ChangeProjectRole(Teams::PROJECT, Teams::GUEST, 'project_member'));

        self::assertSame(ProjectRole::Member, $this->project->membershipOf(Uuid::fromString(Teams::GUEST))->role());
        self::assertSame('project_member', $world->grants->grants[0]->value());

        try {
            new ChangeProjectRoleHandler($world->access(), $world->roles(), $this->grants($world))(new ChangeProjectRole(Teams::PROJECT, Teams::GUEST, 'team_admin'));
            self::fail('Not a project role.');
        } catch (RoleNotAssignable) {
        }

        $world->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Viewer);
        $this->expectException(PermissionDenied::class);
        new ChangeProjectRoleHandler($world->access(), $world->roles(), $this->grants($world))(new ChangeProjectRole(Teams::PROJECT, Teams::GUEST, 'project_viewer'));
    }

    #[Test]
    public function it_should_grant_and_revoke_extras_held_by_the_actor(): void
    {
        $world = $this->world();
        $change = new ExtraPermissionChange($world->access(), $world->roles(), $this->grants($world));

        new GrantExtraPermissionHandler($change)(new GrantExtraPermission(Teams::TEAM, null, Teams::MEMBER, 'host.enroll'));
        self::assertSame([Permission::HostEnroll], $this->team->membershipOf(Uuid::fromString(Teams::MEMBER))->extraPermissions());
        new RevokeExtraPermissionHandler($change)(new RevokeExtraPermission(Teams::TEAM, null, Teams::MEMBER, 'host.enroll'));
        self::assertSame([], $this->team->membershipOf(Uuid::fromString(Teams::MEMBER))->extraPermissions());

        new GrantExtraPermissionHandler($change)(new GrantExtraPermission(Teams::TEAM, Teams::PROJECT, Teams::GUEST, 'project.scan_now'));
        self::assertSame([Permission::ProjectScanNow], $this->project->membershipOf(Uuid::fromString(Teams::GUEST))->extraPermissions());
        new RevokeExtraPermissionHandler($change)(new RevokeExtraPermission(Teams::TEAM, Teams::PROJECT, Teams::GUEST, 'project.scan_now'));
        self::assertSame([], $this->project->membershipOf(Uuid::fromString(Teams::GUEST))->extraPermissions());

        self::assertSame([GrantChange::ExtraGranted, GrantChange::ExtraRevoked, GrantChange::ExtraGranted, GrantChange::ExtraRevoked], array_map(static fn ($g) => $g->change(), $world->grants->grants));

        foreach ([
            static fn () => new GrantExtraPermissionHandler($change)(new GrantExtraPermission(Teams::TEAM, null, Teams::MEMBER, 'not.a.permission')),
            static fn () => new GrantExtraPermissionHandler($change)(new GrantExtraPermission(Teams::OTHER_TEAM, Teams::PROJECT, Teams::GUEST, 'project.scan_now')),
        ] as $gesture) {
            try {
                $gesture();
                self::fail('Not grantable.');
            } catch (PermissionNotGrantable) {
            }
        }
    }

    #[Test]
    public function it_should_refuse_to_grant_a_permission_the_actor_lacks(): void
    {
        $world = $this->world();
        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Member, [Permission::TeamMembersManage, Permission::ProjectMembersManage])], [new ProjectGrant(Teams::PROJECT, Teams::TEAM, ProjectRole::Viewer)]);
        $change = new ExtraPermissionChange($world->access(), $world->roles(), $this->grants($world));

        foreach ([[null, 'host.enroll', Teams::MEMBER], [Teams::PROJECT, 'finding.accept_risk', Teams::GUEST]] as [$projectId, $permission, $userId]) {
            try {
                new GrantExtraPermissionHandler($change)(new GrantExtraPermission(Teams::TEAM, $projectId, $userId, $permission));
                self::fail($permission);
            } catch (PermissionNotGrantable) {
            }
        }

        new RevokeExtraPermissionHandler($change)(new RevokeExtraPermission(Teams::TEAM, null, Teams::MEMBER, 'host.enroll'));
        self::assertCount(1, $world->grants->grants, 'Revoking needs no holding.');

        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Viewer)]);
        foreach ([null, Teams::PROJECT] as $projectId) {
            try {
                new RevokeExtraPermissionHandler(new ExtraPermissionChange($world->access(), $world->roles(), $this->grants($world)))(new RevokeExtraPermission(Teams::TEAM, $projectId, Teams::MEMBER, 'host.enroll'));
                self::fail('Denied.');
            } catch (PermissionDenied) {
            }
        }
    }

    #[Test]
    public function it_should_apply_a_whole_set_of_extras_in_one_change(): void
    {
        $world = $this->world();
        $member = Uuid::fromString(Teams::MEMBER);
        $this->team->grantExtra($member, Permission::TeamAuditView);
        $handler = new ChangeExtraPermissionsHandler($world->access(), new ExtraPermissionChange($world->access(), $world->roles(), $this->grants($world)));

        $handler(new ChangeExtraPermissions(Teams::TEAM, null, Teams::MEMBER, ['host.enroll', 'project.create']));
        self::assertSame([Permission::HostEnroll, Permission::ProjectCreate], $this->team->membershipOf($member)->extraPermissions());
        self::assertSame(
            [[GrantChange::ExtraRevoked, 'team.audit.view'], [GrantChange::ExtraGranted, 'host.enroll'], [GrantChange::ExtraGranted, 'project.create']],
            array_map(static fn ($g): array => [$g->change(), $g->value()], $world->grants->grants),
            'One history entry per difference, none for an unchanged one.',
        );
        self::assertSame([Teams::TEAM], array_values(array_unique($world->teams->locked)));

        $handler(new ChangeExtraPermissions(Teams::TEAM, null, Teams::MEMBER, ['host.enroll', 'project.create']));
        self::assertCount(3, $world->grants->grants, 'Nothing to change.');

        $handler(new ChangeExtraPermissions(Teams::TEAM, Teams::PROJECT, Teams::GUEST, ['project.scan_now']));
        self::assertSame([Permission::ProjectScanNow], $this->project->membershipOf(Uuid::fromString(Teams::GUEST))->extraPermissions());

        foreach ([
            [Teams::TEAM, null, Teams::MEMBER, ['host.enroll', 'team.edit']],
            [Teams::TEAM, null, Teams::MEMBER, ['not.a.permission']],
            [Teams::OTHER_TEAM, Teams::PROJECT, Teams::ADMIN, []],
        ] as [$teamId, $projectId, $userId, $permissions]) {
            try {
                $handler(new ChangeExtraPermissions($teamId, $projectId, $userId, $permissions));
                self::fail('Not grantable.');
            } catch (PermissionNotGrantable) {
            }
        }
        self::assertSame([Permission::HostEnroll, Permission::ProjectCreate], $this->team->membershipOf($member)->extraPermissions(), 'All or nothing: refused before any change.');
    }

    #[Test]
    public function it_should_list_the_extras_a_member_may_receive_with_what_the_actor_holds(): void
    {
        $world = $this->world();
        $member = Uuid::fromString(Teams::MEMBER);
        $this->team->grantExtra($member, Permission::HostEnroll);
        $query = new GetMemberPermissionsHandler($world->permissions, $world->teams, $world->projects);

        $team = $query(new GetMemberPermissions(Teams::TEAM, null, Teams::MEMBER));
        self::assertSame('team_member', $team->role);
        self::assertSame(array_map(static fn (Permission $p): string => $p->value, RolePermissions::grantableToTeamRole(TeamRole::Member)), array_map(static fn (PermissionChoice $c): string => $c->permission, $team->choices));
        self::assertSame(['host.enroll'], $team->granted());
        self::assertTrue($team->choices[0]->actorHolds, 'A TEAM_ADMIN holds every team permission.');

        $project = $query(new GetMemberPermissions(Teams::TEAM, Teams::PROJECT, Teams::GUEST));
        self::assertEquals([new PermissionChoice('project.scan_now', false, true)], $project->choices);

        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Member, [Permission::TeamMembersManage])]);
        $query = new GetMemberPermissionsHandler($world->permissions, $world->teams, $world->projects);
        $choices = $query(new GetMemberPermissions(Teams::TEAM, null, Teams::MEMBER))->choices;
        $enroll = array_first(array_filter($choices, static fn (PermissionChoice $c): bool => 'host.enroll' === $c->permission));
        $order = array_first(array_filter($choices, static fn (PermissionChoice $c): bool => 'host.order' === $c->permission));
        self::assertNotNull($enroll);
        self::assertNotNull($order);
        self::assertTrue($enroll->isGrantableByActor(), 'Granted: may be revoked by whom does not hold it.');
        self::assertFalse($enroll->actorHolds);
        self::assertFalse($order->isGrantableByActor());

        foreach ([
            [new GetMemberPermissions(Teams::OTHER_TEAM, null, Teams::MEMBER), ResourceNotFound::class],
            [new GetMemberPermissions(Teams::TEAM, null, Teams::GUEST), ResourceNotFound::class],
            [new GetMemberPermissions(Teams::OTHER_TEAM, Teams::PROJECT, Teams::GUEST), ResourceNotFound::class],
            [new GetMemberPermissions(Teams::TEAM, Teams::PROJECT, Teams::GUEST), PermissionDenied::class],
        ] as [$ask, $expected]) {
            try {
                $query($ask);
                self::fail($expected);
            } catch (ResourceNotFound|PermissionDenied $refusal) {
                self::assertSame($expected, $refusal::class);
            }
        }

        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Viewer)]);
        $this->expectException(PermissionDenied::class);
        new GetMemberPermissionsHandler($world->permissions, $world->teams, $world->projects)(new GetMemberPermissions(Teams::TEAM, null, Teams::MEMBER));
    }

    #[Test]
    public function it_should_remove_a_team_member_from_every_project_of_the_team(): void
    {
        $world = $this->world();
        $this->team->admit(Uuid::fromString(Teams::GUEST), TeamRole::Guest, Teams::now());
        new RemoveTeamMemberHandler($world->access(), $world->roles(), $world->projects, $this->grants($world))(new RemoveTeamMember(Teams::TEAM, Teams::GUEST));

        self::assertFalse($this->team->isMember(Uuid::fromString(Teams::GUEST)));
        self::assertFalse($this->project->isMember(Uuid::fromString(Teams::GUEST)));
        self::assertSame(GrantChange::Removed, $world->grants->grants[0]->change());
        self::assertSame('team_guest', $world->grants->grants[0]->value());

        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Member, [Permission::TeamMembersManage])]);
        try {
            new RemoveTeamMemberHandler($world->access(), $world->roles(), $world->projects, $this->grants($world))(new RemoveTeamMember(Teams::TEAM, Teams::ADMIN));
            self::fail('Above the actor.');
        } catch (RoleNotAssignable) {
        }

        $world->as(Teams::MEMBER, TeamRole::Viewer);
        $this->expectException(PermissionDenied::class);
        new RemoveTeamMemberHandler($world->access(), $world->roles(), $world->projects, $this->grants($world))(new RemoveTeamMember(Teams::TEAM, Teams::MEMBER));
    }

    #[Test]
    public function it_should_remove_a_project_member_only(): void
    {
        $world = $this->world();
        $this->team->admit(Uuid::fromString(Teams::GUEST), TeamRole::Guest, Teams::now());
        new RemoveProjectMemberHandler($world->access(), $world->roles(), $this->grants($world))(new RemoveProjectMember(Teams::PROJECT, Teams::GUEST));

        self::assertFalse($this->project->isMember(Uuid::fromString(Teams::GUEST)));
        self::assertTrue($this->team->isMember(Uuid::fromString(Teams::GUEST)), 'A TEAM_GUEST without project stays.');
        self::assertSame('project_viewer', $world->grants->grants[0]->value());

        $world->as(Teams::MEMBER, TeamRole::Member);
        $this->expectException(PermissionDenied::class);
        new RemoveProjectMemberHandler($world->access(), $world->roles(), $this->grants($world))(new RemoveProjectMember(Teams::PROJECT, Teams::GUEST));
    }

    #[Test]
    public function it_should_suspend_and_restore_every_membership_of_an_account(): void
    {
        $world = $this->world()->asSystem();
        $other = Teams::withAdmin(Teams::OTHER_TEAM);
        $other->admit(Uuid::fromString(Teams::GUEST), TeamRole::Viewer, Teams::now());
        $world->teams->add($other);
        $this->team->admit(Uuid::fromString(Teams::GUEST), TeamRole::Guest, Teams::now());
        $cascade = new MembershipCascade($world->actor, $world->access(), $world->teams, $world->projects);

        new SuspendMembershipsOfUserHandler($cascade)(new SuspendMembershipsOfUser(Teams::GUEST));
        self::assertFalse($this->team->membershipOf(Uuid::fromString(Teams::GUEST))->isActive());
        self::assertFalse($other->membershipOf(Uuid::fromString(Teams::GUEST))->isActive());
        self::assertFalse($this->project->membershipOf(Uuid::fromString(Teams::GUEST))->isActive());
        self::assertSame([Teams::TEAM, Teams::OTHER_TEAM], $world->teams->locked, 'Locked in team id order.');

        new RestoreMembershipsOfUserHandler($cascade)(new RestoreMembershipsOfUser(Teams::GUEST));
        self::assertTrue($this->team->membershipOf(Uuid::fromString(Teams::GUEST))->isActive());
        self::assertTrue($this->project->membershipOf(Uuid::fromString(Teams::GUEST))->isActive());
    }

    #[Test]
    public function it_should_let_only_the_system_or_a_super_admin_cascade(): void
    {
        $world = $this->world();
        new SuspendMembershipsOfUserHandler(new MembershipCascade($world->actor, $world->asSuperAdmin()->access(), $world->teams, $world->projects))(new SuspendMembershipsOfUser(Teams::MEMBER));
        self::assertFalse($this->team->membershipOf(Uuid::fromString(Teams::MEMBER))->isActive());

        $world->as(Teams::ADMIN, TeamRole::Admin);
        $this->expectException(PermissionDenied::class);
        new RestoreMembershipsOfUserHandler(new MembershipCascade($world->actor, $world->access(), $world->teams, $world->projects))(new RestoreMembershipsOfUser(Teams::MEMBER));
    }

    #[Test]
    public function it_should_react_to_account_events_and_send_invitations(): void
    {
        $commands = new RecordingCommands();
        $cascade = new CascadeAccountStatus($commands);
        $cascade(new AccountDeactivated(Teams::MEMBER));
        $cascade(new AccountReactivated(Teams::MEMBER));
        self::assertEquals([new SuspendMembershipsOfUser(Teams::MEMBER), new RestoreMembershipsOfUser(Teams::MEMBER)], $commands->dispatched);

        $sender = new class implements InvitationSender {
            /** @var list<array{string, string, string}> */
            public array $sent = [];

            public function send(string $invitationId, string $email, string $token): void
            {
                $this->sent[] = [$invitationId, $email, $token];
            }
        };
        new SendInvitation($sender)(new InvitationIssued('i1', 'a@example.com', 't1'));
        new SendInvitation($sender)(new InvitationReissued('i2', 'b@example.com', 't2'));
        self::assertSame([['i1', 'a@example.com', 't1'], ['i2', 'b@example.com', 't2']], $sender->sent);
    }

    private function world(): World
    {
        $world = new World()->as(Teams::ADMIN, TeamRole::Admin);
        $this->team = Teams::withAdmin();
        $this->team->admit(Uuid::fromString(Teams::MEMBER), TeamRole::Member, Teams::now());
        $world->teams->add($this->team);
        $this->project = Teams::project();
        $this->project->admit(Uuid::fromString(Teams::GUEST), ProjectRole::Viewer, Teams::now());
        $world->projects->add($this->project);

        return $world;
    }

    private function grants(World $world): MemberGrants
    {
        return new MemberGrants($world->grants, $world->access(), $world->clock);
    }
}
