<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Query\Admin\Handler\ListAllAgentsHandler;
use Fleet\Application\Query\Admin\ListAllAgents;
use Fleet\Application\Query\Team\GetAgent;
use Fleet\Application\Query\Team\GetTeamFleet;
use Fleet\Application\Query\Team\Handler\GetAgentHandler;
use Fleet\Application\Query\Team\Handler\GetTeamFleetHandler;
use Fleet\Application\Query\Team\Handler\ListEnrollmentTokensHandler;
use Fleet\Application\Query\Team\Handler\ListTeamAgentsHandler;
use Fleet\Application\Query\Team\Handler\ListTeamHostsHandler;
use Fleet\Application\Query\Team\ListEnrollmentTokens;
use Fleet\Application\Query\Team\ListTeamAgents;
use Fleet\Application\Query\Team\ListTeamHosts;
use Fleet\Application\Query\View\AgentRow;
use Fleet\Application\Query\View\FleetView;
use Fleet\Application\Query\View\HostRow;
use Fleet\Application\Query\View\TokenRow;
use Fleet\Application\Release\AgentRelease;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\TeamAccess;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Query\Page;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\FixedReleases;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;
use Tests\Fleet\Support\RecordingFleetReadModel;
use Tests\Organization\Support\Teams;

final class UiQueriesTest extends TestCase
{
    #[Test]
    public function it_should_tell_the_fleet_gestures_and_the_console_key(): void
    {
        $world = new FleetWorld()->as('team_member', Permission::HostEnroll, Permission::HostOrder);
        $view = new GetTeamFleetHandler($world->access(), $world->signer)(new GetTeamFleet(Fleet::TEAM));

        self::assertEquals(new FleetView(Fleet::TEAM, 'Platform', [
            'host.enroll' => true, 'host.approve' => false, 'host.revoke' => false, 'host.delete' => false, 'host.order' => true, 'host.edit' => false,
        ], $world->signer->publicKey()->text(), $world->signer->publicKey()->fingerprint()), $view);
        self::assertTrue($view->can('host.enroll'));
        self::assertFalse($view->can('host.approve'));
        self::assertFalse($view->can('unknown'));

        $world->as('team_guest');
        $this->assertRefused(PermissionDenied::class, static fn () => new GetTeamFleetHandler($world->access(), $world->signer)(new GetTeamFleet(Fleet::TEAM)));
        $this->assertRefused(ResourceNotFound::class, static fn () => new GetTeamFleetHandler($world->access(), $world->signer)(new GetTeamFleet(Fleet::OTHER_TEAM)));
        $world->teams[Fleet::TEAM] = new TeamAccess(Fleet::TEAM, 'Platform', null, true);
        self::assertSame('Platform', new GetTeamFleetHandler($world->access(), $world->signer)(new GetTeamFleet(Fleet::TEAM))->teamName, 'The super admin sees every fleet.');
    }

    #[Test]
    public function it_should_list_the_fleet_of_a_visible_team_only(): void
    {
        $world = new FleetWorld()->as('team_viewer');
        $read = new RecordingFleetReadModel();
        $read->hosts = [new HostRow(Fleet::HOST, 'web-01', 'Debian 12', 'x86_64', 1, 2, null, Fleet::now())];
        $read->agents = [$this->agentRow()];

        self::assertEquals(new Page($read->hosts, 1, 9), new ListTeamHostsHandler($world->access(), $read)(new ListTeamHosts(Fleet::TEAM)));
        self::assertEquals(new Page($read->agents, 1, 9), new ListTeamAgentsHandler($world->access(), $read)(new ListTeamAgents(Fleet::TEAM)));
        self::assertSame([['hosts', Fleet::TEAM], ['agents', Fleet::TEAM]], $read->calls);

        $world->as('team_guest');
        $this->assertRefused(PermissionDenied::class, static fn () => new ListTeamHostsHandler($world->access(), $read)(new ListTeamHosts(Fleet::TEAM)));
        $this->assertRefused(PermissionDenied::class, static fn () => new ListTeamAgentsHandler($world->access(), $read)(new ListTeamAgents(Fleet::TEAM)));
        $this->assertRefused(ResourceNotFound::class, static fn () => new ListTeamAgentsHandler($world->access(), $read)(new ListTeamAgents(Fleet::OTHER_TEAM)));
    }

    #[Test]
    public function it_should_name_the_environment_of_each_token(): void
    {
        $world = new FleetWorld()->as('team_viewer');
        $world->environments = [new TeamEnvironment(Fleet::ENVIRONMENT, 'production', Teams::PROJECT, 'Shop')];
        $read = new RecordingFleetReadModel();
        $read->tokens = [
            new TokenRow(Fleet::TOKEN, 'reusable', 'pending', Fleet::ENVIRONMENT, 1, 5, Fleet::now(), Fleet::now(), true),
            new TokenRow(Fleet::ORDER, 'single', 'pending', null, 0, 1, Fleet::now(), Fleet::now(), true),
            new TokenRow(Fleet::HOST, 'single', 'pending', Fleet::TEAM, 0, 1, Fleet::now(), Fleet::now(), false),
        ];

        $page = new ListEnrollmentTokensHandler($world->access(), $read, $world->queries, $world->clock)(new ListEnrollmentTokens(Fleet::TEAM));
        self::assertSame(['Shop / production', '', ''], array_map(static fn (TokenRow $row): string => $row->environment, $page->items));
        self::assertSame([3, 9], [$page->filtered, $page->total]);
        self::assertEquals($read->tokens[0]->withEnvironment('Shop / production'), $page->items[0]);
        self::assertSame([['tokens', [Fleet::TEAM, '2026-11-09T08:00:00+00:00']]], $read->calls);

        $world->as('team_guest');
        $this->assertRefused(PermissionDenied::class, static fn () => new ListEnrollmentTokensHandler($world->access(), $read, $world->queries, $world->clock)(new ListEnrollmentTokens(Fleet::TEAM)));
    }

    #[Test]
    public function it_should_show_one_agent_to_its_team(): void
    {
        $world = new FleetWorld()->as('team_viewer');
        $world->agents->add(Fleet::agent());
        $releases = new FixedReleases(new AgentRelease('0.2.0', []));
        $handler = new GetAgentHandler($world->access(), $world->agents, $world->hosts, $releases);
        self::assertEquals(new AgentRow(Fleet::AGENT, Fleet::TEAM, 'pending', Fleet::FINGERPRINT, 'container', 'compose', 'shop', '0.1.0', Fleet::HOST, '', Fleet::now(), Fleet::now()), $handler(new GetAgent(Fleet::AGENT)), 'A container agent: upgraded by its image.');
        $host = Fleet::hostAgent();
        $world->agents->add($host);
        self::assertNull($handler(new GetAgent($host->id()->toString()))->upgradeTo, 'Pending.');
        $host->approve(Uuid::v7(), Fleet::now());
        self::assertSame('0.2.0', $handler(new GetAgent($host->id()->toString()))->upgradeTo);
        $releases->latest = new AgentRelease('0.1.0', []);
        self::assertNull($handler(new GetAgent($host->id()->toString()))->upgradeTo, 'Already there.');
        $releases->latest = null;
        self::assertNull($handler(new GetAgent($host->id()->toString()))->upgradeTo, 'No release.');
        $world->hosts->add(Fleet::host());
        self::assertSame('web-01', $handler(new GetAgent(Fleet::AGENT))->hostname);

        $this->assertRefused(ResourceNotFound::class, static fn () => $handler(new GetAgent('not-a-uuid')));
        $this->assertRefused(ResourceNotFound::class, static fn () => $handler(new GetAgent(Fleet::ORDER)));
        unset($world->teams[Fleet::TEAM]);
        $this->assertRefused(ResourceNotFound::class, static fn () => $handler(new GetAgent(Fleet::AGENT)));
    }

    #[Test]
    public function it_should_list_every_agent_with_its_team_to_the_instance_only(): void
    {
        $world = new FleetWorld();
        $read = new RecordingFleetReadModel();
        $read->agents = [$this->agentRow(), $this->agentRow(Fleet::OTHER_TEAM)];
        $handler = new ListAllAgentsHandler($world->permissions, $read, $world->queries);
        $this->assertRefused(PermissionDenied::class, static fn () => $handler(new ListAllAgents()));
        self::assertSame([], $read->calls);

        $world->permissions->instance = [Permission::InstanceAgentsView];
        $page = $handler(new ListAllAgents());
        self::assertSame(['Platform', ''], array_map(static fn (AgentRow $row): string => $row->teamName, $page->items));
        self::assertSame([[Fleet::AGENT, Fleet::TEAM, 'web-01'], [Fleet::AGENT, Fleet::OTHER_TEAM, 'web-01']], array_map(static fn (AgentRow $r): array => [$r->id, $r->teamId, $r->hostname], $page->items));
        self::assertEquals($this->agentRow(teamName: 'Platform'), $page->items[0]);
        self::assertSame([['agents', null]], $read->calls);
        self::assertSame([Fleet::TEAM, Fleet::OTHER_TEAM], $world->queries->asked[0]->teamIds ?? null);

        $read->agents = [];
        self::assertSame([], $handler(new ListAllAgents())->items);
        self::assertCount(1, $world->queries->asked, 'No team to name.');
    }

    private function agentRow(string $teamId = Fleet::TEAM, string $teamName = ''): AgentRow
    {
        return new AgentRow(Fleet::AGENT, $teamId, 'approved', Fleet::FINGERPRINT, 'host', null, null, '0.1.0', Fleet::HOST, 'web-01', Fleet::now(), Fleet::now(), $teamName);
    }

    /**
     * @param class-string<\Throwable> $class
     * @param \Closure(): mixed        $gesture
     */
    private function assertRefused(string $class, \Closure $gesture): void
    {
        try {
            $gesture();
            self::fail('Expected '.$class);
        } catch (\Throwable $refused) {
            self::assertInstanceOf($class, $refused, $refused->getMessage());
        }
    }
}
