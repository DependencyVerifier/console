<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Query\Instance\GetDefaultQuotas;
use Organization\Application\Query\Instance\Handler\GetDefaultQuotasHandler;
use Organization\Application\Query\Invitation\Handler\FindPendingInvitationHandler;
use Organization\Application\Query\MemberEmails;
use Organization\Application\Query\Project\GetProject;
use Organization\Application\Query\Project\Handler\GetProjectHandler;
use Organization\Application\Query\Project\Handler\ListEnvironmentsHandler;
use Organization\Application\Query\Project\Handler\ListProjectMembersHandler;
use Organization\Application\Query\Project\Handler\ListProjectsHandler;
use Organization\Application\Query\Project\Handler\ProjectVisibility;
use Organization\Application\Query\Project\ListEnvironments;
use Organization\Application\Query\Project\ListProjectMembers;
use Organization\Application\Query\Project\ListProjects;
use Organization\Application\Query\Team\GetTeam;
use Organization\Application\Query\Team\Handler\GetTeamHandler;
use Organization\Application\Query\Team\Handler\ListInvitationsHandler;
use Organization\Application\Query\Team\Handler\ListTeamMembersHandler;
use Organization\Application\Query\Team\Handler\ListTeamsHandler;
use Organization\Application\Query\Team\ListInvitations;
use Organization\Application\Query\Team\ListTeamMembers;
use Organization\Application\Query\Team\ListTeams;
use Organization\Application\Query\View\MemberRow;
use Organization\Application\Query\View\QuotaUsage;
use Organization\Application\Query\View\TeamSummary;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\TeamQuotas;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\FindPendingInvitation;
use Shared\Application\Contract\Organization\PendingInvitation;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Tests\Organization\Support\FixedQueries;
use Tests\Organization\Support\RecordingReadModel;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

final class QueriesTest extends TestCase
{
    private const string NOW = '2026-11-09T08:00:00+00:00';

    #[Test]
    public function it_should_show_a_team_with_its_gauges_and_gestures_to_its_members_only(): void
    {
        $world = $this->world()->as(Teams::MEMBER, TeamRole::Member);
        $read = new RecordingReadModel(projects: 2, members: 3, invitations: 1);
        $view = new GetTeamHandler($world->permissions, $world->teams, $read, $world->clock)(new GetTeam(Teams::TEAM));

        self::assertSame([Teams::TEAM, 'Platform', 'platform', 'team_member', true], [$view->id, $view->name, $view->slug, $view->role, $view->adminEstablished]);
        self::assertSame([3, 2, 1], [$view->memberCount, $view->projectCount, $view->pendingInvitationCount]);
        self::assertEquals(Teams::now(), $view->createdAt);
        $defaults = TeamQuotas::defaults();
        self::assertEquals(array_map(static fn (QuotaName $name): QuotaUsage => new QuotaUsage(
            $name->value,
            $defaults->get($name)->toStorage(),
            match ($name) {
                QuotaName::MaxProjects => 2, QuotaName::MaxMembers => 4, default => 0,
            },
            \in_array($name, [QuotaName::MaxProjects, QuotaName::MaxMembers], true),
        ), QuotaName::cases()), $view->quotas);
        self::assertSame(['team.edit' => false, 'team.members.manage' => false, 'team.audit.view' => false, 'project.create' => false, 'team.quotas.manage' => false], $view->can);
        self::assertContains(['pendingInvitations', [Teams::TEAM, self::NOW]], $read->calls);

        $world->as(Teams::ADMIN, TeamRole::Admin);
        self::assertTrue(new GetTeamHandler($world->permissions, $world->teams, $read, $world->clock)(new GetTeam(Teams::TEAM))->can('team.members.manage'));
        self::assertTrue(new GetTeamHandler($world->permissions, $world->teams, $read, $world->clock)(new GetTeam(Teams::TEAM))->can('project.create'));
        self::assertTrue(new GetTeamHandler($world->asSuperAdmin()->permissions, $world->teams, $read, $world->clock)(new GetTeam(Teams::TEAM))->can('team.quotas.manage'));

        foreach ([[Teams::TEAM, Teams::OTHER_TEAM], [Teams::OTHER_TEAM, Teams::OTHER_TEAM]] as [$teamId, $memberOf]) {
            $world->as(Teams::MEMBER, TeamRole::Member, teamId: $memberOf);
            $this->assertNotFound(static fn () => new GetTeamHandler($world->permissions, $world->teams, $read, $world->clock)(new GetTeam($teamId)));
        }
    }

    #[Test]
    public function it_should_list_the_teams_of_the_actor_with_their_role(): void
    {
        $world = $this->world()->as(Teams::MEMBER, TeamRole::Viewer);
        $read = new RecordingReadModel();
        $read->answer([new TeamSummary(Teams::TEAM, 'Platform', 'platform', null, 3, 1, Teams::now())], 4, 9);

        $page = new ListTeamsHandler($world->permissions, $read)(new ListTeams());
        self::assertEquals(new Page([new TeamSummary(Teams::TEAM, 'Platform', 'platform', 'team_viewer', 3, 1, Teams::now())], 4, 9), $page);
        self::assertSame([['teams', [Teams::TEAM]]], $read->calls);

        new ListTeamsHandler($world->asSuperAdmin()->permissions, $read)(new ListTeams());
        self::assertSame([['teams', [Teams::TEAM]], ['teams', null]], $read->calls);
    }

    #[Test]
    public function it_should_list_team_members_with_their_email_but_not_to_a_guest(): void
    {
        $world = $this->world()->as(Teams::MEMBER, TeamRole::Viewer);
        $read = new RecordingReadModel();
        $read->answer([$this->member(Teams::ADMIN), $this->member(Teams::GUEST)], 2, 5);
        $queries = new FixedQueries([FindAccounts::class => static fn (object $q): array => [Teams::ADMIN => new AccountView(Teams::ADMIN, 'admin@example.com', true)]]);

        $page = new ListTeamMembersHandler($world->permissions, $read, new MemberEmails($queries))(new ListTeamMembers(Teams::TEAM, new PageRequest()));
        self::assertEquals(new Page([$this->member(Teams::ADMIN, 'admin@example.com'), $this->member(Teams::GUEST)], 2, 5), $page);
        self::assertEquals([new FindAccounts([Teams::ADMIN, Teams::GUEST])], $queries->asked);
        self::assertSame([['teamMembers', Teams::TEAM]], $read->calls);

        $world->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Viewer);
        try {
            new ListTeamMembersHandler($world->permissions, $read, new MemberEmails($queries))(new ListTeamMembers(Teams::TEAM, new PageRequest()));
            self::fail('A guest sees its projects only.');
        } catch (PermissionDenied) {
        }
        $this->assertNotFound(static fn () => new ListTeamMembersHandler($world->permissions, $read, new MemberEmails($queries))(new ListTeamMembers(Teams::OTHER_TEAM, new PageRequest())));
    }

    #[Test]
    public function it_should_not_ask_identity_for_an_empty_page(): void
    {
        $queries = new FixedQueries([]);
        $empty = new Page([], 0, 7);

        self::assertSame($empty, new MemberEmails($queries)->fill($empty));
        self::assertSame([], $queries->asked);
    }

    #[Test]
    public function it_should_list_invitations_to_member_managers(): void
    {
        $world = $this->world()->as(Teams::ADMIN, TeamRole::Admin);
        $read = new RecordingReadModel();

        self::assertEquals(new Page([], 0, 0), new ListInvitationsHandler($world->permissions, $read, $world->clock)(new ListInvitations(Teams::TEAM, new PageRequest())));
        self::assertSame([['invitations', [Teams::TEAM, self::NOW]]], $read->calls);

        $world->as(Teams::MEMBER, TeamRole::Member);
        try {
            new ListInvitationsHandler($world->permissions, $read, $world->clock)(new ListInvitations(Teams::TEAM, new PageRequest()));
            self::fail('No member management.');
        } catch (PermissionDenied) {
        }
        $this->assertNotFound(static fn () => new ListInvitationsHandler($world->permissions, $read, $world->clock)(new ListInvitations(Teams::OTHER_TEAM, new PageRequest())));
    }

    #[Test]
    public function it_should_list_every_project_or_only_the_guest_ones(): void
    {
        $world = $this->world()->as(Teams::MEMBER, TeamRole::Viewer);
        $read = new RecordingReadModel();

        self::assertEquals(new Page([], 0, 0), new ListProjectsHandler($world->permissions, $read)(new ListProjects(Teams::TEAM, new PageRequest())));
        $world->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Viewer);
        new ListProjectsHandler($world->permissions, $read)(new ListProjects(Teams::TEAM, new PageRequest()));
        self::assertSame([['projects', [Teams::TEAM, null]], ['projects', [Teams::TEAM, [Teams::PROJECT]]]], $read->calls);

        $this->assertNotFound(static fn () => new ListProjectsHandler($world->permissions, $read)(new ListProjects(Teams::OTHER_TEAM, new PageRequest())));
    }

    #[Test]
    public function it_should_show_a_project_with_its_gestures(): void
    {
        $world = $this->world()->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Admin);
        $view = new GetProjectHandler($world->permissions, $world->projects, $world->teams)(new GetProject(Teams::PROJECT));

        self::assertSame([Teams::PROJECT, Teams::TEAM, 'Platform', 'Shop', 0], [$view->id, $view->teamId, $view->teamName, $view->name, $view->environments]);
        self::assertSame(TeamQuotas::defaults()->get(QuotaName::MaxEnvironmentsPerProject)->toStorage(), $view->environmentQuota);
        self::assertSame(['project.edit' => true, 'project.delete' => false, 'project.members.manage' => true, 'project.environments.manage' => true], $view->can);

        $world->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Viewer);
        self::assertSame(['project.edit' => false, 'project.delete' => false, 'project.members.manage' => false, 'project.environments.manage' => false], new GetProjectHandler($world->permissions, $world->projects, $world->teams)(new GetProject(Teams::PROJECT))->can);

        $world->as(Teams::GUEST, TeamRole::Guest);
        $this->assertNotFound(static fn () => new GetProjectHandler($world->permissions, $world->projects, $world->teams)(new GetProject(Teams::PROJECT)));
        $this->assertNotFound(static fn () => new GetProjectHandler($world->asSuperAdmin()->permissions, $world->projects, $world->teams)(new GetProject(Teams::OTHER_TEAM)));

        $orphan = new World()->asSuperAdmin();
        $orphan->projects->add(Teams::project());
        $this->assertNotFound(static fn () => new GetProjectHandler($orphan->permissions, $orphan->projects, $orphan->teams)(new GetProject(Teams::PROJECT)));
    }

    #[Test]
    public function it_should_list_environments_and_members_of_a_visible_project(): void
    {
        $world = $this->world()->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Viewer);
        $read = new RecordingReadModel();
        $visibility = new ProjectVisibility($world->permissions, $world->projects);
        $emails = new MemberEmails(new FixedQueries([]));

        self::assertEquals(new Page([], 0, 0), new ListEnvironmentsHandler($visibility, $read)(new ListEnvironments(Teams::PROJECT, new PageRequest())));
        self::assertEquals(new Page([], 0, 0), new ListProjectMembersHandler($visibility, $read, $emails)(new ListProjectMembers(Teams::PROJECT, new PageRequest())));
        self::assertSame([['environments', Teams::PROJECT], ['projectMembers', Teams::PROJECT]], $read->calls);

        $world->as(Teams::GUEST, TeamRole::Guest);
        $visibility = new ProjectVisibility($world->permissions, $world->projects);
        $this->assertNotFound(static fn () => new ListEnvironmentsHandler($visibility, $read)(new ListEnvironments(Teams::PROJECT, new PageRequest())));
        $this->assertNotFound(static fn () => new ListProjectMembersHandler($visibility, $read, $emails)(new ListProjectMembers(Teams::OTHER_TEAM, new PageRequest())));
    }

    #[Test]
    public function it_should_find_a_pending_invitation_or_nothing(): void
    {
        $world = new World();
        $read = new RecordingReadModel();
        $read->pending = new PendingInvitation('i1', Teams::TEAM, 'a@example.com', 'Platform', null, 'team_admin', Teams::now());

        self::assertSame($read->pending, new FindPendingInvitationHandler($read, $world->clock)(FindPendingInvitation::byTokenHash('h1')));
        self::assertSame([['pendingInvitation', ['h1', null, self::NOW]]], $read->calls);

        $read->pending = null;
        $this->assertNotFound(static fn () => new FindPendingInvitationHandler($read, $world->clock)(FindPendingInvitation::byId('i2')));
    }

    #[Test]
    public function it_should_give_the_default_quotas_to_a_super_admin_only(): void
    {
        $world = new World()->asSuperAdmin();
        $quotas = new GetDefaultQuotasHandler($world->permissions, $world->settings)(new GetDefaultQuotas());

        self::assertSame(array_map(static fn ($quota): ?int => $quota->toStorage(), TeamQuotas::defaults()->all()), $quotas);

        $world->as(Teams::ADMIN, TeamRole::Admin);
        $this->expectException(PermissionDenied::class);
        new GetDefaultQuotasHandler($world->permissions, $world->settings)(new GetDefaultQuotas());
    }

    private function world(): World
    {
        $world = new World();
        $world->teams->add(Teams::withAdmin());
        $world->projects->add(Teams::project());

        return $world;
    }

    private function member(string $userId, string $email = ''): MemberRow
    {
        return new MemberRow($userId, $email, 'team_member', ['host.enroll'], true, 1, Teams::now());
    }

    /**
     * @param \Closure(): mixed $gesture
     */
    private function assertNotFound(\Closure $gesture): void
    {
        try {
            $gesture();
            self::fail('Expected a 404.');
        } catch (ResourceNotFound) {
            $this->addToAssertionCount(1);
        }
    }
}
