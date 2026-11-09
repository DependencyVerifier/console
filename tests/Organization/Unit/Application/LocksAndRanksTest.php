<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Command\Invitation\Handler\AcceptInvitationHandler;
use Organization\Application\Command\Invitation\Handler\InvitationAccess;
use Organization\Application\Command\Invitation\Handler\InviteMemberHandler;
use Organization\Application\Command\Invitation\Handler\RevokeInvitationHandler;
use Organization\Application\Command\Invitation\InviteMember;
use Organization\Application\Command\Invitation\RevokeInvitation;
use Organization\Application\Command\Member\ChangeProjectRole;
use Organization\Application\Command\Member\GrantExtraPermission;
use Organization\Application\Command\Member\Handler\ChangeProjectRoleHandler;
use Organization\Application\Command\Member\Handler\ExtraPermissionChange;
use Organization\Application\Command\Member\Handler\GrantExtraPermissionHandler;
use Organization\Application\Command\Member\Handler\MemberGrants;
use Organization\Application\Command\Member\Handler\MembershipCascade;
use Organization\Application\Command\Member\Handler\RemoveProjectMemberHandler;
use Organization\Application\Command\Member\Handler\RevokeExtraPermissionHandler;
use Organization\Application\Command\Member\Handler\SuspendMembershipsOfUserHandler;
use Organization\Application\Command\Member\RemoveProjectMember;
use Organization\Application\Command\Member\RevokeExtraPermission;
use Organization\Application\Command\Member\SuspendMembershipsOfUser;
use Organization\Application\Command\Project\DeleteProject;
use Organization\Application\Command\Project\Handler\DeleteProjectHandler;
use Organization\Application\Command\Project\Handler\RenameProjectHandler;
use Organization\Application\Command\Project\RenameProject;
use Organization\Domain\Authorization\ProjectGrant;
use Organization\Domain\Authorization\TeamGrant;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationNotPending;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\PermissionNotGrantable;
use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\RoleNotAssignable;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\AcceptInvitation;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedTokens;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Organization\Support\FixedPermissions;
use Tests\Organization\Support\FixedQueries;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

/**
 * Every project-level command locks the team of the real project, and a manager never reaches a role above theirs.
 */
final class LocksAndRanksTest extends TestCase
{
    private const string INVITATION = '019a6b2c-5f00-7a11-8b22-3c44d55e6f01';

    private Project $project;

    #[Test]
    public function it_should_lock_the_team_of_the_project_before_changing_it(): void
    {
        $gestures = [
            'rename' => static fn (World $w) => new RenameProjectHandler($w->access())(new RenameProject(Teams::PROJECT, 'Store')),
            'role' => fn (World $w) => new ChangeProjectRoleHandler($w->access(), $w->roles(), $this->grants($w))(new ChangeProjectRole(Teams::PROJECT, Teams::GUEST, 'project_member')),
            'extra' => fn (World $w) => new GrantExtraPermissionHandler(new ExtraPermissionChange($w->access(), $w->roles(), $this->grants($w)))(new GrantExtraPermission(Teams::TEAM, Teams::PROJECT, Teams::GUEST, 'project.scan_now')),
            'remove' => fn (World $w) => new RemoveProjectMemberHandler($w->access(), $w->roles(), $this->grants($w))(new RemoveProjectMember(Teams::PROJECT, Teams::GUEST)),
            'revoke invitation' => static fn (World $w) => new RevokeInvitationHandler(new InvitationAccess($w->access(), $w->invitations))(new RevokeInvitation(self::INVITATION)),
            'delete' => static fn (World $w) => new DeleteProjectHandler($w->access(), $w->projects, $w->invitations, [])(new DeleteProject(Teams::PROJECT)),
        ];

        foreach ($gestures as $name => $gesture) {
            $world = $this->world();
            $gesture($world);
            self::assertSame([Teams::TEAM], $world->teams->locked, $name);
        }
    }

    #[Test]
    public function it_should_keep_project_roles_above_the_manager_out_of_reach(): void
    {
        $world = $this->world();
        $world->actor = FixedActor::user(Teams::MEMBER);
        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Guest)], [new ProjectGrant(Teams::PROJECT, Teams::TEAM, ProjectRole::Member, [Permission::ProjectMembersManage])]);
        $this->project->admit(Uuid::fromString(Teams::ADMIN), ProjectRole::Admin, Teams::now());

        $gestures = [
            'invite an admin' => fn () => $this->invite($world)(new InviteMember(Uuid::v7()->toRfc4122(), Teams::TEAM, Teams::PROJECT, 'a@example.com', 'project_admin')),
            'promote to admin' => fn () => new ChangeProjectRoleHandler($world->access(), $world->roles(), $this->grants($world))(new ChangeProjectRole(Teams::PROJECT, Teams::GUEST, 'project_admin')),
            'demote an admin' => fn () => new ChangeProjectRoleHandler($world->access(), $world->roles(), $this->grants($world))(new ChangeProjectRole(Teams::PROJECT, Teams::ADMIN, 'project_viewer')),
            'remove an admin' => fn () => new RemoveProjectMemberHandler($world->access(), $world->roles(), $this->grants($world))(new RemoveProjectMember(Teams::PROJECT, Teams::ADMIN)),
            'grant an extra to an admin' => fn () => new GrantExtraPermissionHandler(new ExtraPermissionChange($world->access(), $world->roles(), $this->grants($world)))(new GrantExtraPermission(Teams::TEAM, Teams::PROJECT, Teams::ADMIN, 'project.scan_now')),
            'revoke an extra from an admin' => fn () => new RevokeExtraPermissionHandler(new ExtraPermissionChange($world->access(), $world->roles(), $this->grants($world)))(new RevokeExtraPermission(Teams::TEAM, Teams::PROJECT, Teams::ADMIN, 'project.scan_now')),
        ];
        foreach ($gestures as $name => $gesture) {
            try {
                $gesture();
                self::fail($name);
            } catch (RoleNotAssignable) {
            }
        }
        self::assertSame([], $world->grants->grants);
    }

    #[Test]
    public function it_should_keep_team_roles_above_the_manager_out_of_reach(): void
    {
        $world = $this->world();
        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Member, [Permission::TeamMembersManage, Permission::HostEnroll])]);
        $change = new ExtraPermissionChange($world->access(), $world->roles(), $this->grants($world));
        foreach ([
            'grant an extra to an admin' => static fn () => new GrantExtraPermissionHandler($change)(new GrantExtraPermission(Teams::TEAM, null, Teams::ADMIN, 'host.enroll')),
            'revoke an extra from an admin' => static fn () => new RevokeExtraPermissionHandler($change)(new RevokeExtraPermission(Teams::TEAM, null, Teams::ADMIN, 'host.enroll')),
        ] as $name => $gesture) {
            try {
                $gesture();
                self::fail($name);
            } catch (RoleNotAssignable) {
            }
        }
        self::assertSame([], $world->grants->grants);

        $this->expectException(RoleNotAssignable::class);
        $this->invite($world)(new InviteMember(Uuid::v7()->toRfc4122(), Teams::TEAM, null, 'a@example.com', 'team_admin'));
    }

    #[Test]
    public function it_should_check_a_project_invitation_on_its_real_project(): void
    {
        $world = $this->world()->asSuperAdmin();
        $world->projects->projects = [];
        try {
            new RevokeInvitationHandler(new InvitationAccess($world->access(), $world->invitations))(new RevokeInvitation(self::INVITATION));
            self::fail('Project gone.');
        } catch (ResourceNotFound) {
        }

        $world = $this->world()->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Member);
        $this->expectException(PermissionDenied::class);
        new RevokeInvitationHandler(new InvitationAccess($world->access(), $world->invitations))(new RevokeInvitation(self::INVITATION));
    }

    #[Test]
    public function it_should_refuse_a_project_invitation_whose_project_is_gone(): void
    {
        $world = $this->world();
        $world->projects->projects = [];
        $world->actor = FixedActor::user(Teams::MEMBER);
        $queries = new FixedQueries([FindAccounts::class => static fn (): array => [Teams::MEMBER => new AccountView(Teams::MEMBER, 'jane@example.com', true)]]);

        $this->expectException(InvitationNotPending::class);
        new AcceptInvitationHandler($world->actor, $queries, $world->invitations, $world->teams, $world->projects, $world->grants, $world->clock)(new AcceptInvitation(self::INVITATION));
    }

    #[Test]
    public function it_should_hold_a_project_extra_to_grant_it_but_not_to_revoke_it(): void
    {
        $world = $this->world();
        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Guest)], [new ProjectGrant(Teams::PROJECT, Teams::TEAM, ProjectRole::Viewer, [Permission::ProjectMembersManage])]);
        $this->project->grantExtra(Uuid::fromString(Teams::GUEST), Permission::ProjectScanNow);
        $change = new ExtraPermissionChange($world->access(), $world->roles(), $this->grants($world));

        new RevokeExtraPermissionHandler($change)(new RevokeExtraPermission(Teams::TEAM, Teams::PROJECT, Teams::GUEST, 'project.scan_now'));
        self::assertSame([], $this->project->membershipOf(Uuid::fromString(Teams::GUEST))->extraPermissions());

        $this->expectException(PermissionNotGrantable::class);
        new GrantExtraPermissionHandler($change)(new GrantExtraPermission(Teams::TEAM, Teams::PROJECT, Teams::GUEST, 'project.scan_now'));
    }

    #[Test]
    public function it_should_go_on_with_the_next_team_when_one_vanished(): void
    {
        $world = $this->world()->asSystem();
        $other = Teams::withAdmin(Teams::OTHER_TEAM);
        $other->admit(Uuid::fromString(Teams::GUEST), TeamRole::Viewer, Teams::now());
        $world->teams->add($other);
        $world->teams->find(TeamId::fromString(Teams::TEAM))?->admit(Uuid::fromString(Teams::GUEST), TeamRole::Guest, Teams::now());
        $world->teams->vanished = [Teams::TEAM];

        new SuspendMembershipsOfUserHandler(new MembershipCascade($world->actor, $world->access(), $world->teams, $world->projects))(new SuspendMembershipsOfUser(Teams::GUEST));

        self::assertFalse($other->membershipOf(Uuid::fromString(Teams::GUEST))->isActive());
    }

    private function world(): World
    {
        $world = new World()->as(Teams::ADMIN, TeamRole::Admin);
        $world->teams->add(Teams::withAdmin());
        $this->project = Teams::project();
        $this->project->admit(Uuid::fromString(Teams::GUEST), ProjectRole::Viewer, Teams::now());
        $world->projects->add($this->project);
        $world->invitations->add(Invitation::issue(InvitationId::fromString(self::INVITATION), 'jane@example.com', InvitationTarget::project(TeamId::fromString(Teams::TEAM), ProjectId::fromString(Teams::PROJECT), ProjectRole::Viewer), Uuid::fromString(Teams::ADMIN), 't', 'h', $world->clock->now()));

        return $world;
    }

    private function grants(World $world): MemberGrants
    {
        return new MemberGrants($world->grants, $world->access(), $world->clock);
    }

    private function invite(World $world): InviteMemberHandler
    {
        return new InviteMemberHandler($world->access(), $world->roles(), $world->invitations, new FixedTokens(), new PrefixTokenHasher(), $world->clock);
    }
}
