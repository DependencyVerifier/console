<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Query\Contract\Handler\FindTeamEnvironmentHandler;
use Organization\Application\Query\Contract\Handler\GetTeamAccessHandler;
use Organization\Application\Query\Contract\Handler\GetTeamQuotaHandler;
use Organization\Application\Query\Contract\Handler\ListTeamEnvironmentsHandler;
use Organization\Domain\Model\Quota;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamQuotas;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\FindTeamEnvironment;
use Shared\Application\Contract\Organization\GetTeamAccess;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamAccess;
use Shared\Application\Contract\Organization\TeamEnvironment;
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
