<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Query\Contract\Handler\FilterRecipientsHandler;
use Organization\Application\Query\Contract\Handler\FindTeamEnvironmentHandler;
use Organization\Application\Query\Contract\Handler\FindTeamNamesHandler;
use Organization\Application\Query\Contract\Handler\GetTeamAccessHandler;
use Organization\Application\Query\Contract\Handler\GetTeamQuotaHandler;
use Organization\Application\Query\Contract\Handler\GetVisibleProjectsHandler;
use Organization\Application\Query\Contract\Handler\ListActorTeamsHandler;
use Organization\Application\Query\Contract\Handler\ListTeamEnvironmentsHandler;
use Organization\Domain\Authorization\EffectivePermissions;
use Organization\Domain\Authorization\ProjectGrant;
use Organization\Domain\Authorization\TeamGrant;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\Quota;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamQuotas;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\ActorTeam;
use Shared\Application\Contract\Organization\FilterRecipients;
use Shared\Application\Contract\Organization\FindTeamEnvironment;
use Shared\Application\Contract\Organization\FindTeamNames;
use Shared\Application\Contract\Organization\GetTeamAccess;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListActorTeams;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamAccess;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Contract\Organization\VisibleProjects;
use Tests\Organization\Support\FixedMembers;
use Tests\Organization\Support\FixedPermissions;
use Tests\Organization\Support\RecordingReadModel;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

/**
 * The queries Organization publishes to the other contexts (Fleet first).
 */
final class ContractQueriesTest extends TestCase
{
    private const string ENVIRONMENT = '019a6b2c-4f00-7a11-8b22-3c44d55e6f01';

    #[Test]
    public function it_should_tell_the_actor_role_on_a_team_it_belongs_to_only(): void
    {
        $world = $this->world()->as(Teams::MEMBER, TeamRole::Viewer);
        self::assertEquals(new TeamAccess(Teams::TEAM, 'Platform', 'team_viewer', false), new GetTeamAccessHandler($world->permissions, $world->teams)(new GetTeamAccess(Teams::TEAM)));
        self::assertEquals(new TeamAccess(Teams::TEAM, 'Platform', null, true), new GetTeamAccessHandler($world->asSuperAdmin()->permissions, $world->teams)(new GetTeamAccess(Teams::TEAM)));

        foreach ([[Teams::TEAM, Teams::OTHER_TEAM], [Teams::OTHER_TEAM, Teams::OTHER_TEAM]] as [$teamId, $memberOf]) {
            $world->as(Teams::MEMBER, TeamRole::Admin, teamId: $memberOf);
            $this->assertNotFound(static fn () => new GetTeamAccessHandler($world->permissions, $world->teams)(new GetTeamAccess($teamId)));
        }
    }

    #[Test]
    public function it_should_show_the_fleet_to_every_team_role_but_the_guest(): void
    {
        self::assertTrue(new TeamAccess(Teams::TEAM, 'Platform', 'team_viewer', false)->seesFleet());
        self::assertTrue(new TeamAccess(Teams::TEAM, 'Platform', null, true)->seesFleet());
        self::assertFalse(new TeamAccess(Teams::TEAM, 'Platform', 'team_guest', false)->seesFleet());
        self::assertFalse(new TeamAccess(Teams::TEAM, 'Platform', null, false)->seesFleet());
    }

    #[Test]
    public function it_should_answer_the_limit_of_a_team_quota(): void
    {
        $world = $this->world();
        $handler = new GetTeamQuotaHandler($world->teams);
        self::assertSame(25, $handler(new GetTeamQuota(Teams::TEAM, 'max_agents')));

        $quotas = [];
        foreach (QuotaName::cases() as $name) {
            $quotas[$name->value] = QuotaName::MaxAgents === $name ? Quota::unlimited() : Quota::of(4);
        }
        $world->teams->find(TeamId::fromString(Teams::TEAM))?->changeQuotas(TeamQuotas::of($quotas));
        self::assertNull($handler(new GetTeamQuota(Teams::TEAM, 'max_agents')));
        self::assertSame(4, $handler(new GetTeamQuota(Teams::TEAM, 'max_reusable_enrollment_tokens')));
        $this->assertNotFound(static fn () => $handler(new GetTeamQuota(Teams::OTHER_TEAM, 'max_agents')));
    }

    #[Test]
    public function it_should_find_an_environment_of_the_team_only(): void
    {
        $read = new RecordingReadModel();
        $environment = new TeamEnvironment(self::ENVIRONMENT, 'production', Teams::PROJECT, 'Shop');
        $read->items = [$environment];

        self::assertSame($environment, new FindTeamEnvironmentHandler($read)(new FindTeamEnvironment(Teams::TEAM, self::ENVIRONMENT)));
        self::assertNull(new FindTeamEnvironmentHandler($read)(new FindTeamEnvironment(Teams::TEAM, 'not-a-uuid')), 'Never sent to the database.');
        $read->items = [];
        self::assertNull(new FindTeamEnvironmentHandler($read)(new FindTeamEnvironment(Teams::OTHER_TEAM, self::ENVIRONMENT)));

        $read->items = [$environment, $environment];
        self::assertSame([$environment, $environment], new ListTeamEnvironmentsHandler($read)(new ListTeamEnvironments(Teams::TEAM)));
        self::assertSame([
            ['teamEnvironments', [Teams::TEAM, self::ENVIRONMENT]],
            ['teamEnvironments', [Teams::OTHER_TEAM, self::ENVIRONMENT]],
            ['teamEnvironments', [Teams::TEAM, null]],
        ], $read->calls, 'The invalid id never reaches the database.');
    }

    #[Test]
    public function it_should_tell_which_projects_of_a_team_the_actor_sees(): void
    {
        $world = $this->world()->as(Teams::MEMBER, TeamRole::Viewer);
        $handler = static fn () => new GetVisibleProjectsHandler($world->permissions, $world->teams)(new GetVisibleProjects(Teams::TEAM));
        self::assertEquals(new VisibleProjects(true), $handler());
        self::assertTrue($handler()->sees('any'));

        $world->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Viewer);
        self::assertEquals(new VisibleProjects(false, [Teams::PROJECT]), $handler());
        self::assertTrue($handler()->sees(Teams::PROJECT));
        self::assertFalse($handler()->sees(Teams::TEAM));

        $world->as(Teams::MEMBER, TeamRole::Admin, teamId: Teams::OTHER_TEAM);
        $this->assertNotFound($handler);
        $this->assertNotFound(static fn () => new GetVisibleProjectsHandler($world->asSuperAdmin()->permissions, $world->teams)(new GetVisibleProjects(Teams::OTHER_TEAM)));
        self::assertEquals(new VisibleProjects(true), new GetVisibleProjectsHandler($world->permissions, $world->teams)(new GetVisibleProjects(Teams::TEAM)), 'The super admin sees every project.');
    }

    #[Test]
    public function it_should_list_the_teams_of_the_actor_by_name(): void
    {
        $read = new RecordingReadModel();
        $third = '019a6b2c-2f00-7a11-8b22-3c44d55e6f03';
        $fourth = '019a6b2c-2f00-7a11-8b22-3c44d55e6f04';
        $read->teamNames = [Teams::TEAM => 'platform', Teams::OTHER_TEAM => 'Alpha', $third => 'Platform', $fourth => 'Gone'];
        $permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Admin), new TeamGrant(Teams::OTHER_TEAM, TeamRole::Guest), new TeamGrant($third, TeamRole::Viewer)]);
        self::assertEquals([
            new ActorTeam(Teams::OTHER_TEAM, 'Alpha', 'team_guest'), new ActorTeam(Teams::TEAM, 'platform', 'team_admin'), new ActorTeam($third, 'Platform', 'team_viewer'),
        ], new ListActorTeamsHandler($permissions, $read)(new ListActorTeams()), 'By name whatever the case, then by id; only its teams.');

        $none = new RecordingReadModel();
        self::assertSame([], new ListActorTeamsHandler(FixedPermissions::superAdmin(), $none)(new ListActorTeams()), 'A super admin: its own teams only.');
        self::assertSame([], $none->calls, 'Nothing to ask.');
    }

    #[Test]
    public function it_should_name_the_known_teams_only(): void
    {
        $read = new RecordingReadModel();
        self::assertSame([Teams::TEAM => 'Platform'], new FindTeamNamesHandler($read)(new FindTeamNames([Teams::TEAM, Teams::OTHER_TEAM, 'nope'])));
        self::assertSame([['teamNames', [Teams::TEAM, Teams::OTHER_TEAM]]], $read->calls, 'Invalid ids never reach the database.');
        $none = new RecordingReadModel();
        self::assertSame([], new FindTeamNamesHandler($none)(new FindTeamNames(['nope'])));
        self::assertSame([], $none->calls, 'Nothing to ask.');
    }

    #[Test]
    public function it_should_keep_the_recipients_who_see_a_project_or_the_fleet(): void
    {
        $project = '019a6b2c-4f00-7a11-8b22-3c44d55e6f11';
        $handler = new FilterRecipientsHandler(new FixedMembers([
            'viewer' => self::grant([new TeamGrant(Teams::TEAM, TeamRole::Viewer)]),
            'guest-of-project' => self::grant([new TeamGrant(Teams::TEAM, TeamRole::Guest)], [new ProjectGrant($project, Teams::TEAM, ProjectRole::Viewer)]),
            'guest' => self::grant([new TeamGrant(Teams::TEAM, TeamRole::Guest)]),
            'elsewhere' => self::grant([new TeamGrant(Teams::OTHER_TEAM, TeamRole::Admin)], [new ProjectGrant($project, Teams::OTHER_TEAM, ProjectRole::Viewer)]),
        ]));
        $everyone = ['stranger', 'viewer', 'guest', 'guest-of-project', 'elsewhere'];

        self::assertSame(['viewer', 'guest-of-project'], $handler(new FilterRecipients(Teams::TEAM, $project, $everyone)));
        self::assertSame(['viewer'], $handler(new FilterRecipients(Teams::TEAM, null, $everyone)), 'The fleet: who sees every project.');
        self::assertSame([], $handler(new FilterRecipients(Teams::TEAM, null, [])));
    }

    /**
     * @param list<TeamGrant>    $teams
     * @param list<ProjectGrant> $projects
     */
    private static function grant(array $teams, array $projects = []): EffectivePermissions
    {
        return EffectivePermissions::resolve(false, $teams, $projects);
    }

    private function world(): World
    {
        $world = new World();
        $world->teams->add(Teams::withAdmin());

        return $world;
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
