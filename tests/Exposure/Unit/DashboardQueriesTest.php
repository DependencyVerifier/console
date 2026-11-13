<?php

declare(strict_types=1);

namespace Tests\Exposure\Unit;

use Exposure\Application\Query\ExposureFilter;
use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\FindingCriteria;
use Exposure\Application\Query\GetExposureSummary;
use Exposure\Application\Query\GetPortfolio;
use Exposure\Application\Query\GetProductionExposure;
use Exposure\Application\Query\Handler\FindingPlaces;
use Exposure\Application\Query\Handler\GetExposureSummaryHandler;
use Exposure\Application\Query\Handler\GetPortfolioHandler;
use Exposure\Application\Query\Handler\GetProductionExposureHandler;
use Exposure\Application\Query\Handler\ListFindingHostsHandler;
use Exposure\Application\Query\Handler\ListTeamFindingsHandler;
use Exposure\Application\Query\ListFindingHosts;
use Exposure\Application\Query\ListTeamFindings;
use Exposure\Application\Query\View\ExposureSummary;
use Exposure\Application\Query\View\FindingRow;
use Exposure\Application\Query\View\ProductionExposure;
use Exposure\Application\Security\FindingAccess;
use Exposure\Domain\Model\Finding;
use Exposure\Domain\Model\FindingFacts;
use Exposure\Domain\Model\FindingKind;
use Exposure\Domain\Model\SubjectPlacement;
use Exposure\Domain\Model\SubjectType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\ActorTeam;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListActorTeams;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Contract\Organization\VisibleProjects;
use Shared\Application\Query\Page;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Exposure\Support\InMemoryFindingReadModel;
use Tests\Exposure\Support\InMemoryFindings;
use Tests\Exposure\Support\InMemoryRiskAcceptances;
use Tests\Organization\Support\FixedQueries;

/**
 * What a dashboard or a list of findings reads: the whole team for a member, the projects of a TEAM_GUEST (never the
 * fleet), a project or an environment it sees, a host for whom sees every project; 404 otherwise.
 */
final class DashboardQueriesTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string SHOP = '019a6b2c-3f00-7a11-8b22-3c44d55e6f01';
    private const string BLOG = '019a6b2c-3f00-7a11-8b22-3c44d55e6f02';
    private const string SHOP_ENV = '019a6b2c-4f00-7a11-8b22-3c44d55e6f01';
    private const string BLOG_ENV = '019a6b2c-4f00-7a11-8b22-3c44d55e6f02';
    private const string HOST = '019a6b2c-7f00-7a11-8b22-3c44d55e6f01';

    private bool $guest = false;

    #[Test]
    public function it_should_filter_each_perimeter_as_the_actor_sees_it(): void
    {
        $filter = fn (ExposureScope $scope, ?string $id = null): ExposureFilter => $this->access()->filter(self::TEAM, $scope, $id);
        self::assertEquals(new ExposureFilter(self::TEAM), $filter(ExposureScope::Team));
        self::assertEquals(new ExposureFilter(self::TEAM, projectId: self::BLOG), $filter(ExposureScope::Project, self::BLOG));
        self::assertEquals(new ExposureFilter(self::TEAM, environmentId: self::BLOG_ENV), $filter(ExposureScope::Environment, self::BLOG_ENV));
        self::assertEquals(new ExposureFilter(self::TEAM, hostId: self::HOST), $filter(ExposureScope::Host, self::HOST));
        $this->assertNotFound(static fn () => $filter(ExposureScope::Environment, '019a6b2c-4f00-7a11-8b22-3c44d55e6f09'), 'Not an environment of the team.');
        $this->assertNotFound(static fn () => $filter(ExposureScope::Environment));

        $this->guest = true;
        self::assertEquals(new ExposureFilter(self::TEAM, [self::SHOP]), $filter(ExposureScope::Team), 'Its projects only, never the fleet.');
        self::assertEquals(new ExposureFilter(self::TEAM, [self::SHOP], projectId: self::SHOP), $filter(ExposureScope::Project, self::SHOP));
        self::assertEquals(new ExposureFilter(self::TEAM, [self::SHOP], environmentId: self::SHOP_ENV), $filter(ExposureScope::Environment, self::SHOP_ENV));
        $this->assertNotFound(static fn () => $filter(ExposureScope::Project, self::BLOG));
        $this->assertNotFound(static fn () => $filter(ExposureScope::Project));
        $this->assertNotFound(static fn () => $filter(ExposureScope::Environment, self::BLOG_ENV));
        $this->assertNotFound(static fn () => $filter(ExposureScope::Host, self::HOST));
        $this->assertNotFound(fn () => $this->access()->filter('019a6b2c-1f00-7a11-8b22-3c44d55e6f09', ExposureScope::Team, null), 'Another team.');
    }

    #[Test]
    public function it_should_show_what_is_open_in_production(): void
    {
        $findings = new InMemoryFindings();
        $read = new InMemoryFindingReadModel($findings, new InMemoryRiskAcceptances());
        $clock = new MockClock('2026-11-12 08:00:00', 'UTC');
        $finding = Finding::detect(Uuid::v7(), Uuid::fromString(self::TEAM), SubjectType::Application, Uuid::v7(), new FindingFacts(FindingKind::Vulnerability, 'GHSA-1 lodash', 'Injection', 'lodash', '1', '2', 'critical', 9.8, true, 0.9, null, null, null, []), $clock->now());
        $finding->place(new SubjectPlacement('shop / php', Uuid::fromString(self::SHOP_ENV), Uuid::fromString(self::SHOP), null, null, true));
        $findings->add($finding);
        $production = fn (bool $marked, ExposureScope $scope = ExposureScope::Team, ?string $id = null, int $urgent = GetProductionExposure::URGENT): ProductionExposure => new GetProductionExposureHandler(
            $this->access(), $read, new FixedQueries([
                ListTeamEnvironments::class => static fn (): array => [new TeamEnvironment(self::BLOG_ENV, 'production', self::BLOG, 'Blog', $marked), new TeamEnvironment(self::SHOP_ENV, 'staging', self::SHOP, 'Shop')],
                GetVisibleProjects::class => fn (): VisibleProjects => $this->guest ? new VisibleProjects(false, [self::SHOP]) : new VisibleProjects(true),
            ]), $clock, $this->places(),
        )(new GetProductionExposure(self::TEAM, $scope, $id, $urgent));

        $view = $production(true);
        self::assertTrue($view->hasProduction);
        self::assertFalse($view->isQuiet());
        self::assertSame(['Injection', 'Shop / production'], [$view->urgent[0]->title, $view->urgent[0]->environment], 'Named.');
        self::assertEquals(new ExposureFilter(self::TEAM, production: true), $read->filters[0][0], 'The production of the perimeter only.');
        self::assertSame([5], $read->limits);
        self::assertSame(GetProductionExposure::URGENT, new GetProductionExposure(self::TEAM)->urgent);
        self::assertSame([ExposureScope::Team, null], [new GetProductionExposure(self::TEAM)->scope, new GetProductionExposure(self::TEAM)->id]);
        self::assertFalse($production(false)->hasProduction, 'No production environment.');
        $production(true, ExposureScope::Project, self::SHOP, 10);
        self::assertSame([5, 5, 10], $read->limits);
        self::assertEquals(new ExposureFilter(self::TEAM, projectId: self::SHOP, production: true), $read->filters[4][0]);
        $this->guest = true;
        self::assertFalse($production(true)->hasProduction, 'A production environment of a project it does not see.');
        $findings->findings = [];
        self::assertTrue(new ProductionExposure(true, new ExposureSummary([], 0, [], 0, 0, [], [], []), [])->isQuiet());
    }

    #[Test]
    public function it_should_gather_the_production_of_every_team_of_the_actor(): void
    {
        $read = new InMemoryFindingReadModel(new InMemoryFindings(), new InMemoryRiskAcceptances());
        $read->projectFigures = [self::BLOG => ['knownExploited' => 1, 'critical' => 2, 'high' => 3, 'endsOfLife' => 4]];
        $row = static fn (string $id, ?string $severity, bool $kev = false, ?string $eol = null, ?float $cvss = null, string $kind = 'vulnerability', string $title = 'T'): FindingRow => new FindingRow(
            $id, self::TEAM, 'application', 'a', $kind, $title, 'p', '1', null, $severity, $cvss, $kev, null, $eol, null, null, [], 'open',
            new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01'), null, null,
        );
        $urgent = [
            $row('e1-soon', null, eol: 'soon', kind: 'eol'), $row('high-low-cvss', 'high', cvss: 7.0), $row('e3-expired', null, eol: 'expired', kind: 'eol'),
            $row('critical', 'critical'), $row('kev', 'high', true), $row('high-cvss', 'high', cvss: 8.1), $row('e2-imminent', null, eol: 'imminent', kind: 'eol'),
            $row('c0-critical', 'critical', title: 'U'),
            $row('vuln-medium', 'medium'), $row('b', 'high', title: 'B'), $row('a', 'high', title: 'A'),
            $row('z2', 'low', title: 'Z'), $row('z1', 'low', title: 'Z'),
        ];
        $production = new ProductionExposure(true, new ExposureSummary([], 0, [], 0, 0, [], [], []), $urgent);
        $asked = [];
        $queries = new FixedQueries([
            ListActorTeams::class => static fn (): array => [new ActorTeam(self::TEAM, 'Alpha', 'team_admin')],
            GetProductionExposure::class => static function (object $q) use ($production, &$asked): ProductionExposure {
                $asked[] = $q;

                return $production;
            },
            ListTeamEnvironments::class => static fn (): array => [
                new TeamEnvironment(self::BLOG_ENV, 'production', self::BLOG, 'Blog', true), new TeamEnvironment(self::SHOP_ENV, 'production', self::SHOP, 'Shop', true),
                new TeamEnvironment('019a6b2c-4f00-7a11-8b22-3c44d55e6f09', 'staging', '019a6b2c-3f00-7a11-8b22-3c44d55e6f09', 'Docs'),
            ],
        ]);
        $portfolio = new GetPortfolioHandler($queries, $this->access(), $read, new MockClock('2026-11-12 08:00:00', 'UTC'))(new GetPortfolio());

        self::assertEquals([new GetProductionExposure(self::TEAM, ExposureScope::Team, null, GetPortfolio::URGENT)], $asked);
        self::assertSame(['kev', 'critical', 'c0-critical', 'high-cvss', 'high-low-cvss', 'a', 'b', 'e3-expired', 'e2-imminent', 'e1-soon'], array_map(static fn (FindingRow $r): string => $r->id, $portfolio->urgent), 'Exploited, critical, high (CVSS, then title), then ends of life; ten at most.');
        self::assertCount(1, $portfolio->teams);
        $team = $portfolio->teams[0];
        self::assertSame([self::TEAM, 'Alpha', 'team_admin'], [$team->teamId, $team->name, $team->role]);
        self::assertSame($production, $team->production);
        self::assertSame([
            ['projectId' => self::BLOG, 'name' => 'Blog', 'knownExploited' => 1, 'critical' => 2, 'high' => 3, 'endsOfLife' => 4],
            ['projectId' => self::SHOP, 'name' => 'Shop', 'knownExploited' => 0, 'critical' => 0, 'high' => 0, 'endsOfLife' => 0],
        ], $team->projects, 'Its projects with a production environment, figures or not.');
        self::assertEquals([new ExposureFilter(self::TEAM, production: true), null], $read->filters[0]);

        $this->guest = true;
        $guest = new GetPortfolioHandler($queries, $this->access(), $read, new MockClock('2026-11-12 08:00:00', 'UTC'))(new GetPortfolio());
        self::assertSame([self::SHOP], array_column($guest->teams[0]->projects, 'projectId'), 'A guest: its projects only.');
        self::assertEquals([new ExposureFilter(self::TEAM, [self::SHOP], production: true), null], $read->filters[1]);
        $sorted = $guest->urgent;
        self::assertSame(['z1', 'z2'], array_map(static fn (FindingRow $r): string => $r->id,
            (new GetPortfolioHandler(new FixedQueries([ListActorTeams::class => static fn (): array => [new ActorTeam(self::TEAM, 'Alpha', 'team_admin')], GetProductionExposure::class => static fn (): ProductionExposure => new ProductionExposure(true, new ExposureSummary([], 0, [], 0, 0, [], [], []), [$row('z2', 'low', title: 'Z'), $row('z1', 'low', title: 'Z')]), ListTeamEnvironments::class => static fn (): array => []]), $this->access(), $read, new MockClock()))(new GetPortfolio())->urgent
        ), 'The same title: by id.');
        self::assertCount(10, $sorted);
    }

    #[Test]
    public function it_should_name_where_the_findings_are(): void
    {
        $row = static fn (?string $environment, string $name = ''): FindingRow => new FindingRow(
            'f1', self::TEAM, 'application', 'a1', 'vulnerability', 'Injection', 'lodash', '4.17.20', '4.17.21', 'high', 7.2, true, 0.2, 'soon',
            new \DateTimeImmutable('2026-12-31'), '4.17.21', ['GHSA-1'], 'open', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-02'),
            new \DateTimeImmutable('2026-11-03'), new \DateTimeImmutable('2026-11-04'), 'shop / php', '4.17.21', self::SHOP, $environment, 'h1', 'web-01', true, $name,
        );
        self::assertEquals($row(self::SHOP_ENV, 'Shop / production'), $row(self::SHOP_ENV)->withEnvironment('Shop / production'), 'Every other field kept.');

        $named = $this->places()->rows(self::TEAM, [$row(self::SHOP_ENV), $row(self::BLOG_ENV), $row(null), $row('019a6b2c-4f00-7a11-8b22-3c44d55e6f09')]);
        self::assertSame(['Shop / production', 'Blog / production', '', ''], array_map(static fn (FindingRow $r): string => $r->environment, $named));
        $page = $this->places()->page(self::TEAM, new Page([$row(self::SHOP_ENV)], 7, 9));
        self::assertSame(['Shop / production', 7, 9], [$page->items[0]->environment, $page->filtered, $page->total]);
        $never = new FindingPlaces(new FixedQueries([]));
        self::assertSame([], $never->rows(self::TEAM, []), 'No row: Organization never asked.');
    }

    #[Test]
    public function it_should_read_the_summary_and_the_list_through_the_filter(): void
    {
        $read = new InMemoryFindingReadModel(new InMemoryFindings(), new InMemoryRiskAcceptances());
        $clock = new MockClock('2026-11-12 08:00:00', 'UTC');
        $summary = new GetExposureSummaryHandler($this->access(), $read, $clock)(new GetExposureSummary(self::TEAM, ExposureScope::Project, self::SHOP));
        self::assertEquals(new ExposureSummary([], 0, [], 0, 0, [], [], []), $summary);
        $criteria = new FindingCriteria('vulnerability', 'high', 'fixed', self::SHOP, self::SHOP_ENV);
        new ListTeamFindingsHandler($this->access(), $read, $clock, $this->places())(new ListTeamFindings(self::TEAM, ExposureScope::Environment, self::SHOP_ENV, $criteria));
        self::assertEquals([[new ExposureFilter(self::TEAM, projectId: self::SHOP), null], [new ExposureFilter(self::TEAM, environmentId: self::SHOP_ENV), $criteria]], $read->filters);

        self::assertSame(['h1' => 'web-01'], new ListFindingHostsHandler($this->access(), $read)(new ListFindingHosts(self::TEAM, ExposureScope::Project, self::SHOP)));
        self::assertEquals([new ExposureFilter(self::TEAM, projectId: self::SHOP), null], $read->filters[2], 'Through the filter of the actor.');
        self::assertSame([ExposureScope::Team, null], [new ListFindingHosts(self::TEAM)->scope, new ListFindingHosts(self::TEAM)->id]);

        $host = '019a6b2c-7f00-7a11-8b22-3c44d55e6f01';
        $where = new FindingCriteria(projectId: self::SHOP, environmentId: self::SHOP_ENV, hostId: $host, production: false);
        self::assertSame([self::SHOP, self::SHOP_ENV, $host, false], [$where->projectId, $where->environmentId, $where->hostId, $where->production]);
        $garbage = new FindingCriteria(projectId: "x' OR 1=1", environmentId: 'nope', hostId: '');
        self::assertSame([null, null, null, null], [$garbage->projectId, $garbage->environmentId, $garbage->hostId, $garbage->production], 'Not an id: no filter, never an SQL error.');

        $defaults = new ListTeamFindings(self::TEAM);
        self::assertSame([ExposureScope::Team, null, 'open', null, null], [$defaults->scope, $defaults->id, $defaults->criteria->status, $defaults->criteria->kind, $defaults->criteria->severity]);
        self::assertSame([ExposureScope::Team, null], [new GetExposureSummary(self::TEAM)->scope, new GetExposureSummary(self::TEAM)->id]);
        self::assertSame(['vulnerability', 'high', 'fixed', self::SHOP, self::SHOP_ENV], [$criteria->kind, $criteria->severity, $criteria->status, $criteria->projectId, $criteria->environmentId]);
        $unknown = new FindingCriteria('nope', 'urgent', 'closed');
        self::assertSame([null, null, 'open'], [$unknown->kind, $unknown->severity, $unknown->status], 'An unknown value is no filter.');
        foreach (['eol', 'outdated'] as $kind) {
            self::assertSame($kind, new FindingCriteria($kind)->kind);
        }
        foreach (['critical', 'medium', 'low'] as $severity) {
            self::assertSame($severity, new FindingCriteria(severity: $severity)->severity);
        }
        foreach (['accepted', 'all'] as $status) {
            self::assertSame($status, new FindingCriteria(status: $status)->status);
        }
        self::assertSame([12, 3, 4], [new ExposureSummary(['critical' => 2, 'high' => 10], 0, ['soon' => 1, 'expired' => 2], 4, 0, [], [], [])->vulnerabilities(), new ExposureSummary([], 0, ['soon' => 1, 'expired' => 2], 4, 0, [], [], [])->endsOfLife(), 4]);
    }

    private function places(): FindingPlaces
    {
        return new FindingPlaces(new FixedQueries([
            ListTeamEnvironments::class => static fn (): array => [new TeamEnvironment(self::BLOG_ENV, 'production', self::BLOG, 'Blog'), new TeamEnvironment(self::SHOP_ENV, 'production', self::SHOP, 'Shop', true)],
        ]));
    }

    private function access(): FindingAccess
    {
        $queries = new FixedQueries([
            GetVisibleProjects::class => fn (object $q): VisibleProjects => $q instanceof GetVisibleProjects && self::TEAM === $q->teamId ? new VisibleProjects(!$this->guest, [self::SHOP]) : throw ResourceNotFound::of('team'),
            ListTeamEnvironments::class => static fn (): array => [new TeamEnvironment(self::BLOG_ENV, 'production', self::BLOG, 'Blog'), new TeamEnvironment(self::SHOP_ENV, 'production', self::SHOP, 'Shop')],
        ]);

        return new FindingAccess($queries, new readonly class implements PermissionChecker {
            public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
            {
                return false;
            }
        });
    }

    private function assertNotFound(\Closure $call, string $message = ''): void
    {
        try {
            $call();
            self::fail($message);
        } catch (ResourceNotFound) {
            $this->addToAssertionCount(1);
        }
    }
}
