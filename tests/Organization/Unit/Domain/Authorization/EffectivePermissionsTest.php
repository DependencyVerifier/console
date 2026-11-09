<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Domain\Authorization;

use Organization\Domain\Authorization\EffectivePermissions;
use Organization\Domain\Authorization\ProjectGrant;
use Organization\Domain\Authorization\TeamGrant;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Authorization\Permission;

/**
 * The example of docs/cadrage.md §7bis ("l'utilisateur 3").
 */
final class EffectivePermissionsTest extends TestCase
{
    private const string TEAM_1 = 'team-1';
    private const string TEAM_3 = 'team-3';

    #[Test]
    public function it_should_resolve_the_published_example(): void
    {
        $user3 = EffectivePermissions::resolve(false, [
            new TeamGrant(self::TEAM_1, TeamRole::Guest),
            new TeamGrant(self::TEAM_3, TeamRole::Guest, [Permission::ProjectCreate]),
        ], [
            new ProjectGrant('t1-p2', self::TEAM_1, ProjectRole::Member, [Permission::FindingAcceptRisk]),
            new ProjectGrant('t1-p4', self::TEAM_1, ProjectRole::Viewer),
            new ProjectGrant('t3-p1', self::TEAM_3, ProjectRole::Admin),
            new ProjectGrant('t3-p5', self::TEAM_3, ProjectRole::Member),
        ]);

        self::assertFalse($user3->isSuperAdmin());
        self::assertTrue($user3->allows(Permission::FindingAcceptRisk, self::TEAM_1, 't1-p2'));
        self::assertTrue($user3->allows(Permission::FindingView, self::TEAM_1, 't1-p4'));
        self::assertFalse($user3->allows(Permission::FindingAcceptRisk, self::TEAM_1, 't1-p4'));
        self::assertFalse($user3->allows(Permission::FindingView, self::TEAM_1, 't1-p1'), 'No project of team 1 by default.');
        self::assertTrue($user3->allows(Permission::ProjectCreate, self::TEAM_3));
        self::assertFalse($user3->allows(Permission::ProjectCreate, self::TEAM_1));
        self::assertTrue($user3->allows(Permission::ProjectMembersManage, self::TEAM_3, 't3-p1'));
        self::assertFalse($user3->allows(Permission::TeamMembersManage, self::TEAM_3));
        self::assertFalse($user3->allows(Permission::FindingView, self::TEAM_3, 't1-p2'), 'A project of another team.');
        self::assertFalse($user3->allows(Permission::FindingView, self::TEAM_1), 'A project permission needs a project.');
        self::assertFalse($user3->allows(Permission::TeamEdit), 'A team permission needs a team.');
        self::assertFalse($user3->allows(Permission::InstanceAuditView));

        self::assertSame(TeamRole::Guest, $user3->teamRole(self::TEAM_1));
        self::assertNull($user3->teamRole('team-2'));
        self::assertTrue($user3->isTeamMember(self::TEAM_1));
        self::assertFalse($user3->isTeamMember('team-2'));
        self::assertFalse($user3->seesAllProjectsOf(self::TEAM_1));
        self::assertTrue($user3->seesProject(self::TEAM_1, 't1-p2'));
        self::assertFalse($user3->seesProject(self::TEAM_1, 't1-p1'));
        self::assertFalse($user3->seesProject(self::TEAM_3, 't1-p2'));
        self::assertSame(['t1-p2', 't1-p4'], $user3->projectIdsOf(self::TEAM_1));
        self::assertSame([self::TEAM_1, self::TEAM_3], $user3->teamIds());
    }

    #[Test]
    public function it_should_give_team_roles_every_project_of_their_team(): void
    {
        $member = EffectivePermissions::resolve(false, [new TeamGrant(self::TEAM_1, TeamRole::Member)], []);

        self::assertTrue($member->allows(Permission::ProjectEnvironmentsManage, self::TEAM_1, 'any-project'));
        self::assertFalse($member->allows(Permission::ProjectEdit, self::TEAM_1, 'any-project'));
        self::assertTrue($member->seesAllProjectsOf(self::TEAM_1));
        self::assertTrue($member->seesProject(self::TEAM_1, 'any-project'));
        self::assertFalse($member->seesAllProjectsOf('team-2'));
    }

    #[Test]
    public function it_should_grant_everything_to_the_super_admin(): void
    {
        $superAdmin = EffectivePermissions::resolve(true, [], []);

        self::assertTrue($superAdmin->isSuperAdmin());
        self::assertTrue($superAdmin->allows(Permission::TeamMembersManage, 'any'));
        self::assertTrue($superAdmin->allows(Permission::InstanceSettings));
        self::assertTrue($superAdmin->isTeamMember('any'));
        self::assertTrue($superAdmin->seesAllProjectsOf('any'));
    }

    #[Test]
    public function it_should_add_the_project_role_on_top_of_the_team_role(): void
    {
        $viewer = EffectivePermissions::resolve(false, [new TeamGrant(self::TEAM_1, TeamRole::Member)], [new ProjectGrant('p', self::TEAM_1, ProjectRole::Viewer, [Permission::ProjectScanNow])]);

        self::assertTrue($viewer->allows(Permission::ProjectEnvironmentsManage, self::TEAM_1, 'p'), 'From the team role.');
        self::assertTrue($viewer->allows(Permission::ProjectScanNow, self::TEAM_1, 'p'), 'From the project extras.');
        self::assertFalse($viewer->allows(Permission::ProjectEdit, self::TEAM_1, 'p'));
    }
}
