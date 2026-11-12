<?php

declare(strict_types=1);

namespace Tests\Exposure\Unit;

use Exposure\Application\Command\Refresh\Handler\RefreshFindingsHandler;
use Exposure\Application\Command\Refresh\Handler\RequestExposureRefreshHandler;
use Exposure\Application\Command\Refresh\RefreshFindings;
use Exposure\Application\Command\Refresh\RequestExposureRefresh;
use Exposure\Application\Content\ExposureTeamContent;
use Exposure\Application\EventHandler\QueueFindingsRefresh;
use Exposure\Application\EventHandler\RequestRefreshOnChange;
use Exposure\Domain\Event\ExposureRefreshRequested;
use Exposure\Domain\Event\FindingFixed;
use Exposure\Domain\Event\FindingOpened;
use Exposure\Domain\Model\Finding;
use Exposure\Domain\Model\FindingKind;
use Exposure\Domain\Model\FindingStatus;
use Exposure\Domain\Model\RiskAcceptance;
use Exposure\Domain\Model\SubjectPlacement;
use Exposure\Domain\Model\SubjectType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Target;
use Shared\Application\Contract\Inventory\ApplicationPlacementChanged;
use Shared\Application\Contract\Inventory\InventoryChanged;
use Shared\Application\Contract\Inventory\ListInventoriedTeams;
use Shared\Application\Contract\Inventory\ListTeamSubjects;
use Shared\Application\Contract\Inventory\TeamSubject;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Contract\Vulnerability\AdvisoriesChanged;
use Shared\Application\Contract\Vulnerability\AssessSubjects;
use Shared\Application\Contract\Vulnerability\Risk;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Exposure\Support\InMemoryFindings;
use Tests\Exposure\Support\InMemoryRiskAcceptances;
use Tests\Exposure\Support\InMemoryTeamExposures;
use Tests\Organization\Support\FixedQueries;
use Tests\Organization\Support\RecordingCommands;

/**
 * The findings of a team follow what the matching produces: new ones open, those no longer produced are fixed,
 * those coming back open again with their first detection; a burst of changes computes each team once.
 */
final class FindingsTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string OTHER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';
    private const string APP = '019a6b31-6e00-7f12-a3b4-c5d6e7f80901';
    private const string HOST = '019a6b2c-7f00-7a11-8b22-3c44d55e6f01';

    private const string PROJECT = '019a6b2c-3f00-7a11-8b22-3c44d55e6f01';
    private const string ENVIRONMENT = '019a6b2c-4f00-7a11-8b22-3c44d55e6f01';

    private ?string $environment = self::ENVIRONMENT;

    private InMemoryFindings $findings;
    private InMemoryTeamExposures $exposures;
    private MockClock $clock;

    /** @var array<string, list<Risk>> */
    private array $risks = [];

    protected function setUp(): void
    {
        $this->findings = new InMemoryFindings();
        $this->exposures = new InMemoryTeamExposures();
        $this->clock = new MockClock('2026-11-12 08:00:00', 'UTC');
    }

    #[Test]
    public function it_should_open_fix_and_reopen_the_findings_of_a_team(): void
    {
        $lodash = new Risk(Risk::VULNERABILITY, 'GHSA-1 lodash', 'Command Injection', 'lodash', '4.17.20', '4.17.21', 'high', 7.2, true, 0.2, aliases: ['GHSA-1', 'CVE-2021-23337']);
        $php = new Risk(Risk::END_OF_LIFE, 'php 8.2', 'php 8.2', 'php', '8.2.26', eolLevel: 'soon', endOfLife: new \DateTimeImmutable('2026-12-31'), latest: '8.2.35');
        $this->risks = ['application:'.self::APP => [$lodash], 'host:'.self::HOST => [$php]];
        $this->refresh();

        self::assertSame([
            'application '.self::APP.' vulnerability GHSA-1 lodash open',
            'host '.self::HOST.' eol php 8.2 open',
        ], $this->summary());
        $vulnerability = $this->findings->findings[0];
        self::assertEquals($lodash->title, $vulnerability->facts()->title);
        self::assertSame([FindingKind::Vulnerability, 'GHSA-1 lodash', SubjectType::Application, self::APP, self::TEAM], [$vulnerability->kind(), $vulnerability->key(), $vulnerability->subjectType(), $vulnerability->subjectId()->toRfc4122(), $vulnerability->teamId()->toRfc4122()]);
        self::assertSame(['4.17.20', '4.17.21', 'high', 7.2, true, 0.2, null, null, null, ['GHSA-1', 'CVE-2021-23337'], 'lodash'], [
            $vulnerability->facts()->installed, $vulnerability->facts()->fixed, $vulnerability->facts()->severity, $vulnerability->facts()->cvss, $vulnerability->facts()->knownExploited,
            $vulnerability->facts()->epss, $vulnerability->facts()->eolLevel, $vulnerability->facts()->endOfLife, $vulnerability->facts()->latest, $vulnerability->facts()->aliases, $vulnerability->facts()->package,
        ]);
        self::assertEquals([new SubjectPlacement('shop / php', Uuid::fromString(self::ENVIRONMENT), Uuid::fromString(self::PROJECT)), new SubjectPlacement('web-01')], [$vulnerability->placement(), $this->findings->findings[1]->placement()]);
        self::assertSame(['soon', '2026-12-31', '8.2.35'], [$this->findings->findings[1]->facts()->eolLevel, $this->findings->findings[1]->facts()->endOfLife?->format('Y-m-d'), $this->findings->findings[1]->facts()->latest]);
        self::assertEquals([new FindingOpened($vulnerability->id()->toRfc4122(), self::TEAM, false)], $vulnerability->releaseEvents());
        self::assertCount(1, $this->findings->findings[1]->releaseEvents());
        $first = $this->clock->now();
        self::assertEquals([$first, $first, null], [$vulnerability->firstSeenAt(), $vulnerability->lastSeenAt(), $vulnerability->fixedAt()]);
        self::assertEquals($first, $this->exposures->exposures[self::TEAM]->refreshedAt());

        $this->clock->modify('+1 day');
        $this->risks = ['application:'.self::APP => [], 'host:'.self::HOST => [new Risk(Risk::END_OF_LIFE, 'php 8.2', 'php 8.2', 'php', '8.2.26', eolLevel: 'imminent')]];
        $this->refresh();
        self::assertSame(['application '.self::APP.' vulnerability GHSA-1 lodash fixed', 'host '.self::HOST.' eol php 8.2 open'], $this->summary(), 'Upgraded: fixed; the end of life comes closer.');
        self::assertEquals([new FindingFixed($vulnerability->id()->toRfc4122(), self::TEAM)], $vulnerability->releaseEvents());
        self::assertEquals($this->clock->now(), $vulnerability->fixedAt());
        self::assertSame('imminent', $this->findings->findings[1]->facts()->eolLevel);
        self::assertEquals($this->clock->now(), $this->findings->findings[1]->lastSeenAt());
        self::assertSame([], $this->findings->findings[1]->releaseEvents(), 'Still open: no event.');

        $this->clock->modify('+1 day');
        $this->environment = '019a6b2c-4f00-7a11-8b22-3c44d55e6f09';
        $this->refresh();
        self::assertSame([], $vulnerability->releaseEvents(), 'Fixed once.');
        self::assertEquals(new SubjectPlacement('web-01'), $this->findings->findings[1]->placement());
        $this->environment = null;

        $this->clock->modify('+1 day');
        $this->risks = ['application:'.self::APP => [$lodash], 'host:'.self::HOST => []];
        $this->refresh();
        self::assertSame(['application '.self::APP.' vulnerability GHSA-1 lodash open', 'host '.self::HOST.' eol php 8.2 fixed'], $this->summary(), 'Rolled back: open again.');
        self::assertEquals([new FindingOpened($vulnerability->id()->toRfc4122(), self::TEAM, true)], $vulnerability->releaseEvents());
        self::assertEquals([$first, null], [$vulnerability->firstSeenAt(), $vulnerability->fixedAt()], 'First detection kept.');
        self::assertCount(2, $this->findings->findings, 'Never duplicated.');

        $this->risks = [];
        $this->refresh(subjects: false);
        self::assertSame(['fixed', 'fixed'], array_map(static fn (Finding $f): string => $f->status()->value, $this->findings->findings), 'The subjects are gone.');
        self::assertSame(FindingStatus::Fixed, $this->findings->findings[0]->status());
    }

    #[Test]
    public function it_should_compute_each_team_once_per_burst_of_changes(): void
    {
        $queries = new FixedQueries([ListInventoriedTeams::class => static fn (): array => [self::TEAM, self::OTHER]]);
        $request = new RequestExposureRefreshHandler($this->exposures, $queries);
        $request(new RequestExposureRefresh(self::TEAM));
        $request(new RequestExposureRefresh());
        $request(new RequestExposureRefresh());
        $team = $this->exposures->exposures[self::TEAM];
        $other = $this->exposures->exposures[self::OTHER];
        self::assertEquals([new ExposureRefreshRequested(self::TEAM)], $team->releaseEvents(), 'Asked three times, queued once.');
        self::assertEquals([new ExposureRefreshRequested(self::OTHER)], $other->releaseEvents());
        self::assertTrue($team->isRefreshRequested());
        self::assertNull($team->refreshedAt());

        $team->refreshing($this->clock->now());
        self::assertFalse($team->isRefreshRequested());
        $request(new RequestExposureRefresh(self::TEAM));
        self::assertEquals([new ExposureRefreshRequested(self::TEAM)], $team->releaseEvents(), 'Asked again once the computation started.');

        $commands = new RecordingCommands();
        new RequestRefreshOnChange($commands)(new InventoryChanged(self::TEAM));
        new RequestRefreshOnChange($commands)(new AdvisoriesChanged([['ecosystem' => 'npm', 'name' => 'lodash']]));
        new RequestRefreshOnChange($commands)(new ApplicationPlacementChanged(self::OTHER));
        new QueueFindingsRefresh($commands)(new ExposureRefreshRequested(self::TEAM));
        self::assertEquals([new RequestExposureRefresh(self::TEAM), new RequestExposureRefresh(), new RequestExposureRefresh(self::OTHER), new RefreshFindings(self::TEAM)], $commands->dispatched);

        self::assertSame(['exposure.request_refresh', []], [new RequestExposureRefresh()->auditAction()->name, new RequestExposureRefresh()->auditContext()->values]);
        self::assertEquals([Target::none(), Target::of('team', self::TEAM)], [new RequestExposureRefresh()->auditTarget(), new RequestExposureRefresh(self::TEAM)->auditTarget()]);
        self::assertSame(['exposure.refresh_findings', []], [new RefreshFindings(self::TEAM)->auditAction()->name, new RefreshFindings(self::TEAM)->auditContext()->values]);
        self::assertEquals(Target::of('team', self::TEAM), new RefreshFindings(self::TEAM)->auditTarget());
    }

    #[Test]
    public function it_should_erase_the_findings_of_a_deleted_team_only(): void
    {
        $this->risks = ['application:'.self::APP => [new Risk(Risk::OUTDATED, 'npm lodash', 'minor', 'lodash', '4.17.20', latest: '4.18.1')]];
        $this->refresh();
        $this->refresh(team: self::OTHER);
        self::assertCount(2, $this->findings->findings);

        $acceptances = new InMemoryRiskAcceptances();
        foreach ($this->findings->findings as $finding) {
            $acceptances->add(RiskAcceptance::accept(Uuid::v7(), $finding, 'Accepted.', $this->clock->now()->modify('+1 month'), Uuid::v7(), $this->clock->now()));
        }
        new ExposureTeamContent($this->findings, $this->exposures, $acceptances)->eraseTeam(self::TEAM);
        self::assertSame([self::OTHER], array_map(static fn (RiskAcceptance $a): string => $a->teamId()->toRfc4122(), $acceptances->acceptances));
        self::assertSame([], $this->findings->ofTeam(Uuid::fromString(self::TEAM)));
        self::assertCount(1, $this->findings->ofTeam(Uuid::fromString(self::OTHER)));
        self::assertArrayNotHasKey(self::TEAM, $this->exposures->exposures);
        self::assertArrayHasKey(self::OTHER, $this->exposures->exposures);
    }

    private function refresh(bool $subjects = true, string $team = self::TEAM): void
    {
        $queries = new FixedQueries([
            ListTeamSubjects::class => fn (): array => $subjects ? [new TeamSubject(TeamSubject::APPLICATION, self::APP, [], [], null, 'shop / php', $this->environment), new TeamSubject(TeamSubject::HOST, self::HOST, [], [], null, 'web-01')] : [],
            ListTeamEnvironments::class => static fn (): array => [new TeamEnvironment(self::ENVIRONMENT, 'production', self::PROJECT, 'Shop')],
            AssessSubjects::class => fn (object $q): array => $q instanceof AssessSubjects ? array_intersect_key($this->risks, array_flip(array_map(static fn (TeamSubject $s): string => $s->key(), $q->subjects))) : [],
        ]);
        new RefreshFindingsHandler($queries, $this->findings, $this->exposures, $this->clock)(new RefreshFindings($team));
    }

    /**
     * @return list<string>
     */
    private function summary(): array
    {
        return array_map(static fn (Finding $f): string => $f->identity().' '.$f->status()->value, $this->findings->findings);
    }
}
