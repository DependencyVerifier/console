<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\RoleNotAssignable;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Domain\Authorization\Permission;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

final class AccessTest extends TestCase
{
    #[Test]
    public function it_should_lock_a_visible_team_only(): void
    {
        $world = new World();
        $team = Teams::withAdmin();
        $world->teams->add($team);
        $world->as(Teams::ADMIN, TeamRole::Admin);

        self::assertSame($team, $world->access()->lockTeam(TeamId::fromString(Teams::TEAM)));
        self::assertSame([Teams::TEAM], $world->teams->locked);

        foreach ([[Teams::OTHER_TEAM, $world], [Teams::TEAM, new World()->as(Teams::GUEST, null)]] as [$id, $scenario]) {
            $scenario->teams->add($team);
            try {
                $scenario->access()->lockTeam(TeamId::fromString($id));
                self::fail('Not visible.');
            } catch (ResourceNotFound) {
            }
        }
    }

    #[Test]
    public function it_should_load_a_visible_project_only(): void
    {
        $world = new World()->as(Teams::GUEST, TeamRole::Guest);
        $project = Teams::project();
        $world->projects->add($project);

        try {
            $world->access()->project(ProjectId::fromString(Teams::PROJECT));
            self::fail('A guest sees only their projects.');
        } catch (ResourceNotFound) {
        }

        $world->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Viewer);
        self::assertSame($project, $world->access()->project(ProjectId::fromString(Teams::PROJECT)));

        $this->expectException(ResourceNotFound::class);
        $world->access()->project(ProjectId::fromString(Teams::OTHER_TEAM));
    }

    #[Test]
    public function it_should_check_permissions_on_the_real_scope(): void
    {
        $world = new World()->as(Teams::MEMBER, TeamRole::Member);
        $access = $world->access();
        $team = TeamId::fromString(Teams::TEAM);
        $project = ProjectId::fromString(Teams::PROJECT);

        $access->assert(Permission::ProjectEnvironmentsManage, $team, $project);
        self::assertTrue($access->holds(Permission::ProjectScanNow, $team, $project));
        self::assertFalse($access->holds(Permission::ProjectEdit, $team, $project));
        self::assertFalse($access->holds(Permission::TeamEdit, $team));
        self::assertSame(TeamRole::Member, $access->teamRole($team));
        self::assertNull($access->projectRole($project));
        self::assertFalse($access->isSuperAdmin());
        self::assertTrue($access->actorId()->equals($world->uuid(Teams::MEMBER)));
        self::assertTrue($access->actorIdOrNull()?->equals($world->uuid(Teams::MEMBER)));

        foreach ([static fn () => $access->assert(Permission::TeamEdit, $team), static fn () => $access->assertInstance(Permission::TeamCreate)] as $gesture) {
            try {
                $gesture();
                self::fail('Denied.');
            } catch (PermissionDenied) {
            }
        }

        self::assertNull(new World()->asSystem()->access()->actorIdOrNull());
        new World()->asSuperAdmin()->access()->assertInstance(Permission::TeamCreate);
    }

    #[Test]
    public function it_should_let_a_role_grant_lower_or_equal_roles_only(): void
    {
        $team = TeamId::fromString(Teams::TEAM);
        $project = ProjectId::fromString(Teams::PROJECT);

        new World()->asSuperAdmin()->roles()->assertTeamRole($team, TeamRole::Admin, TeamRole::Admin);
        new World()->as(Teams::ADMIN, TeamRole::Admin)->roles()->assertTeamRole($team, TeamRole::Admin, TeamRole::Admin);
        $member = new World()->as(Teams::MEMBER, TeamRole::Member)->roles();
        $member->assertTeamRole($team, TeamRole::Member);
        $member->assertTeamRole($team, TeamRole::Guest, TeamRole::Viewer);

        foreach ([
            static fn () => $member->assertTeamRole($team, TeamRole::Admin),
            static fn () => $member->assertTeamRole($team, TeamRole::Viewer, TeamRole::Admin),
            static fn () => new World()->as(Teams::GUEST, null)->roles()->assertTeamRole($team, TeamRole::Guest),
        ] as $gesture) {
            try {
                $gesture();
                self::fail('Not assignable.');
            } catch (RoleNotAssignable) {
            }
        }

        new World()->asSuperAdmin()->roles()->assertProjectRole($team, $project, ProjectRole::Admin, ProjectRole::Admin);
        new World()->as(Teams::ADMIN, TeamRole::Admin)->roles()->assertProjectRole($team, $project, ProjectRole::Admin, ProjectRole::Admin);
        $projectAdmin = new World()->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Admin)->roles();
        $projectAdmin->assertProjectRole($team, $project, ProjectRole::Admin, ProjectRole::Member);
        new World()->as(Teams::GUEST, null, ProjectRole::Admin)->roles()->assertProjectRole($team, $project, ProjectRole::Member);
        $projectMember = new World()->as(Teams::GUEST, TeamRole::Member, ProjectRole::Member)->roles();
        $projectMember->assertProjectRole($team, $project, ProjectRole::Viewer);
        // A TEAM_MEMBER ranks as a PROJECT_MEMBER in the team's projects, with or without a project role of their own.
        new World()->as(Teams::MEMBER, TeamRole::Member)->roles()->assertProjectRole($team, $project, ProjectRole::Member, ProjectRole::Viewer);
        new World()->as(Teams::MEMBER, TeamRole::Member, ProjectRole::Admin)->roles()->assertProjectRole($team, $project, ProjectRole::Admin);

        foreach ([
            static fn () => $projectMember->assertProjectRole($team, $project, ProjectRole::Admin),
            static fn () => $projectMember->assertProjectRole($team, $project, ProjectRole::Viewer, ProjectRole::Admin),
            static fn () => new World()->as(Teams::MEMBER, TeamRole::Member)->roles()->assertProjectRole($team, $project, ProjectRole::Admin),
            static fn () => new World()->as(Teams::MEMBER, TeamRole::Viewer)->roles()->assertProjectRole($team, $project, ProjectRole::Viewer),
        ] as $gesture) {
            try {
                $gesture();
                self::fail('Not assignable.');
            } catch (RoleNotAssignable) {
            }
        }

        $this->addToAssertionCount(6);
    }

    #[Test]
    public function it_should_let_only_a_team_admin_remove_a_member_of_the_team_from_a_project(): void
    {
        $team = TeamId::fromString(Teams::TEAM);
        $project = ProjectId::fromString(Teams::PROJECT);
        $projectAdmin = new World()->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Admin)->roles();

        $projectAdmin->assertProjectRemoval($team, $project, ProjectRole::Viewer, TeamRole::Guest);
        $projectAdmin->assertProjectRemoval($team, $project, ProjectRole::Viewer, null);
        $projectAdmin->assertProjectRemoval($team, $project, ProjectRole::Viewer, TeamRole::Viewer);
        new World()->as(Teams::ADMIN, TeamRole::Admin)->roles()->assertProjectRemoval($team, $project, ProjectRole::Admin, TeamRole::Member);
        new World()->asSuperAdmin()->roles()->assertProjectRemoval($team, $project, ProjectRole::Admin, TeamRole::Admin);

        $refused = [];
        foreach ([
            'a TEAM_MEMBER' => static fn () => $projectAdmin->assertProjectRemoval($team, $project, ProjectRole::Viewer, TeamRole::Member),
            'a TEAM_ADMIN' => static fn () => $projectAdmin->assertProjectRemoval($team, $project, ProjectRole::Viewer, TeamRole::Admin),
            'a member ranked above' => static fn () => new World()->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Member)->roles()->assertProjectRemoval($team, $project, ProjectRole::Admin, TeamRole::Guest),
        ] as $case => $gesture) {
            try {
                $gesture();
            } catch (RoleNotAssignable) {
                $refused[] = $case;
            }
        }
        self::assertSame(['a TEAM_MEMBER', 'a TEAM_ADMIN', 'a member ranked above'], $refused);
    }
}
