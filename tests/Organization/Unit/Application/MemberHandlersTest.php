<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Command\Member\ChangeProjectRole;
use Organization\Application\Command\Member\ChangeTeamRole;
use Organization\Application\Command\Member\GrantExtraPermission;
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
use Organization\Domain\Authorization\ProjectGrant;
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
