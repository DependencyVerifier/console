<?php

declare(strict_types=1);

namespace Tests\Exposure\Unit;

use Exposure\Application\Query\ExposureFilter;
use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\FindingCriteria;
use Exposure\Application\Query\GetExposureSummary;
use Exposure\Application\Query\Handler\GetExposureSummaryHandler;
use Exposure\Application\Query\Handler\ListTeamFindingsHandler;
use Exposure\Application\Query\ListTeamFindings;
use Exposure\Application\Query\View\ExposureSummary;
use Exposure\Application\Security\FindingAccess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Contract\Organization\VisibleProjects;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
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
    public function it_should_read_the_summary_and_the_list_through_the_filter(): void
    {
        $read = new InMemoryFindingReadModel(new InMemoryFindings(), new InMemoryRiskAcceptances());
        $clock = new MockClock('2026-11-12 08:00:00', 'UTC');
        $summary = new GetExposureSummaryHandler($this->access(), $read, $clock)(new GetExposureSummary(self::TEAM, ExposureScope::Project, self::SHOP));
        self::assertEquals(new ExposureSummary([], 0, [], 0, 0, [], [], []), $summary);
        $criteria = new FindingCriteria('vulnerability', 'high', 'fixed', self::SHOP, self::SHOP_ENV);
        new ListTeamFindingsHandler($this->access(), $read, $clock)(new ListTeamFindings(self::TEAM, ExposureScope::Environment, self::SHOP_ENV, $criteria));
        self::assertEquals([[new ExposureFilter(self::TEAM, projectId: self::SHOP), null], [new ExposureFilter(self::TEAM, environmentId: self::SHOP_ENV), $criteria]], $read->filters);

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
