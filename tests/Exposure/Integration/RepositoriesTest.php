<?php

declare(strict_types=1);

namespace Tests\Exposure\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exposure\Application\Query\ExposureFilter;
use Exposure\Application\Query\FindingCriteria;
use Exposure\Application\Query\View\FindingRow;
use Exposure\Domain\Model\Finding;
use Exposure\Domain\Model\FindingFacts;
use Exposure\Domain\Model\FindingKind;
use Exposure\Domain\Model\RiskAcceptance;
use Exposure\Domain\Model\SubjectPlacement;
use Exposure\Domain\Model\SubjectType;
use Exposure\Domain\Model\TeamExposure;
use Exposure\Infrastructure\Doctrine\DbalFindingReadModel;
use Exposure\Infrastructure\Doctrine\DoctrineFindingRepository;
use Exposure\Infrastructure\Doctrine\DoctrineRiskAcceptanceRepository;
use Exposure\Infrastructure\Doctrine\DoctrineTeamExposureRepository;
use Exposure\Infrastructure\Doctrine\ExposureManager;
use PHPUnit\Framework\Attributes\Test;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The Exposure tables against PostgreSQL: one finding per identity, read back whole, erased with their team.
 */
final class RepositoriesTest extends KernelTestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string OTHER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';

    private ExposureManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = new ExposureManager(self::getContainer()->get('doctrine'));
    }

    #[Test]
    public function it_should_keep_one_finding_per_identity_and_erase_a_team(): void
    {
        $findings = new DoctrineFindingRepository($this->manager);
        $exposures = new DoctrineTeamExposureRepository($this->manager);
        $now = new \DateTimeImmutable('2026-11-12 08:00:00');
        $facts = new FindingFacts(FindingKind::EndOfLife, 'php 8.2', 'php 8.2', 'php', '8.2.26', null, null, null, false, null, 'soon', new \DateTimeImmutable('2026-12-31'), '8.2.35', []);
        $subject = Uuid::v7();
        $findings->add(Finding::detect(Uuid::v7(), Uuid::fromString(self::TEAM), SubjectType::Host, $subject, $facts, $now));
        $findings->add(Finding::detect(Uuid::v7(), Uuid::fromString(self::OTHER), SubjectType::Host, $subject, $facts, $now));
        $exposures->add(TeamExposure::of(Uuid::fromString(self::TEAM)));
        $exposures->add(TeamExposure::of(Uuid::fromString(self::OTHER)));
        $this->flush();

        $read = $findings->ofTeam(Uuid::fromString(self::TEAM));
        self::assertCount(1, $read);
        self::assertEquals($facts, $read[0]->facts(), 'Read back whole.');
        self::assertNotNull($exposures->find(Uuid::fromString(self::TEAM)));

        $findings->deleteTeam(Uuid::fromString(self::TEAM));
        $exposures->deleteTeam(Uuid::fromString(self::TEAM));
        $this->flush();
        self::assertSame([], $findings->ofTeam(Uuid::fromString(self::TEAM)));
        self::assertNull($exposures->find(Uuid::fromString(self::TEAM)));
        self::assertCount(1, $findings->ofTeam(Uuid::fromString(self::OTHER)), 'Another team keeps its findings.');

        $findings->add(Finding::detect(Uuid::v7(), Uuid::fromString(self::OTHER), SubjectType::Host, $subject, $facts, $now));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->flush();
    }

    #[Test]
    public function it_should_read_the_findings_of_a_subject_open_and_severe_first(): void
    {
        $findings = new DoctrineFindingRepository($this->manager);
        $acceptances = new DoctrineRiskAcceptanceRepository($this->manager);
        $now = new \DateTimeImmutable('2026-11-12 08:00:00');
        $team = Uuid::fromString(self::TEAM);
        $subject = Uuid::v7();
        $vulnerability = static fn (string $key, ?string $severity, bool $kev = false): FindingFacts => new FindingFacts(FindingKind::Vulnerability, $key, 'Title '.$key, 'pkg-'.$key, '1.0', '1.1', $severity, null, $kev, null, null, null, null, ['CVE-'.$key]);
        $fixed = Finding::detect(Uuid::v7(), $team, SubjectType::Application, $subject, $vulnerability('a', 'critical'), $now);
        $fixed->resolve($now);
        $low = Finding::detect(Uuid::v7(), $team, SubjectType::Application, $subject, $vulnerability('b', 'low'), $now);
        $high = Finding::detect(Uuid::v7(), $team, SubjectType::Application, $subject, $vulnerability('c', 'high'), $now);
        $highKev = Finding::detect(Uuid::v7(), $team, SubjectType::Application, $subject, $vulnerability('d', 'high', true), $now);
        $eol = Finding::detect(Uuid::v7(), $team, SubjectType::Application, $subject, new FindingFacts(FindingKind::EndOfLife, 'php 8.2', 'php 8.2', 'php', '8.2.26', null, null, null, false, null, 'soon', new \DateTimeImmutable('2026-12-31'), '8.2.35', []), $now);
        array_map($findings->add(...), [$fixed, $low, $high, $highKev, $eol, Finding::detect(Uuid::v7(), $team, SubjectType::Host, $subject, $vulnerability('e', 'high'), $now)]);
        $old = RiskAcceptance::accept(Uuid::v7(), $high, 'Old.', new \DateTimeImmutable('2026-12-01'), Uuid::v7(), $now);
        $old->revoke(Uuid::v7(), $now);
        $acceptances->add($old);
        $acceptances->add(RiskAcceptance::accept(Uuid::v7(), $high, 'Not reachable.', new \DateTimeImmutable('2027-01-31'), Uuid::v7(), $now->modify('+1 second')));
        $acceptances->add(RiskAcceptance::accept(Uuid::v7(), $low, 'Expiring.', new \DateTimeImmutable('2026-11-13'), Uuid::v7(), $now));
        $this->flush();

        $read = new DbalFindingReadModel($this->manager->get()->getConnection());
        $open = $read->ofSubject(self::TEAM, 'application', $subject->toRfc4122(), $now, withFixed: false, page: new PageRequest());
        self::assertSame(['Title d', 'Title c', 'Title b', 'php 8.2'], array_map(static fn (FindingRow $r): string => $r->title, $open->items), 'By default without the fixed ones; the accepted ones stay.');
        self::assertSame([4, 4], [$open->filtered, $open->total], 'Counted as listed.');
        $page = $read->ofSubject(self::TEAM, 'application', $subject->toRfc4122(), $now, withFixed: true, page: new PageRequest());
        self::assertSame(['Title d', 'Title c', 'Title b', 'php 8.2', 'Title a'], array_map(static fn (FindingRow $r): string => $r->title, $page->items), 'Open first; vulnerabilities, the most severe and exploited first; then ends of life; fixed last.');
        self::assertSame([5, 5], [$page->filtered, $page->total]);
        self::assertSame(['2027-01-31', '2026-11-13'], [$page->items[1]->acceptedUntil?->format('Y-m-d'), $page->items[2]->acceptedUntil?->format('Y-m-d')]);
        self::assertNull($read->ofSubject(self::TEAM, 'application', $subject->toRfc4122(), new \DateTimeImmutable('2026-11-13'), withFixed: true, page: new PageRequest())->items[2]->acceptedUntil, 'Expired on its day.');
        self::assertSame(['2026-12-31', 'soon', ['CVE-d'], true, 'fixed'], [$page->items[3]->endOfLife?->format('Y-m-d'), $page->items[3]->eolLevel, $page->items[0]->aliases, $page->items[0]->knownExploited, $page->items[4]->status]);
        self::assertNotNull($page->items[4]->fixedAt);

        $searched = $read->ofSubject(self::TEAM, 'application', $subject->toRfc4122(), $now, withFixed: true, page: new PageRequest(search: 'cve-b'));
        self::assertSame([['Title b'], 1, 5], [array_map(static fn (FindingRow $r): string => $r->title, $searched->items), $searched->filtered, $searched->total]);
        self::assertSame('Title c', $read->find($high->id()->toRfc4122(), $now)?->title);
        self::assertNull($read->find(Uuid::v7()->toRfc4122(), $now));
        self::assertSame(['Not reachable.', 'Old.'], array_map(static fn (RiskAcceptance $a): string => $a->justification(), $acceptances->ofFinding($high->id())), 'The latest first.');
        self::assertSame($high->id()->toRfc4122(), $findings->find($high->id())?->id()->toRfc4122());

        $acceptances->deleteTeam($team);
        $this->flush();
        self::assertSame([], $acceptances->ofFinding($high->id()));
    }

    #[Test]
    public function it_should_sum_up_and_list_a_perimeter(): void
    {
        $findings = new DoctrineFindingRepository($this->manager);
        $acceptances = new DoctrineRiskAcceptanceRepository($this->manager);
        $today = new \DateTimeImmutable('2026-11-12 08:00:00');
        $team = Uuid::fromString(self::TEAM);
        [$shop, $blog, $shopEnv, $blogEnv] = [Uuid::v7(), Uuid::v7(), Uuid::v7(), Uuid::v7()];
        [$host, $otherHost] = [Uuid::v7(), Uuid::v7()];
        $hosts = ['shop' => [$host, 'web-01', true], 'web-01' => [$host, 'web-01', true], 'blog' => [$otherHost, 'web-02', false]];
        $add = static function (string $name, ?Uuid $env, ?Uuid $project, FindingFacts $facts, string $seen, SubjectType $type = SubjectType::Application, ?Uuid $subject = null) use ($findings, $team, $hosts): Finding {
            $finding = Finding::detect(Uuid::v7(), $team, $type, $subject ?? Uuid::v5(Uuid::fromString(self::TEAM), $name), $facts, new \DateTimeImmutable($seen));
            [$hostId, $hostName, $production] = $hosts[$name] ?? [null, null, false];
            $finding->place(new SubjectPlacement($name, $env, $project, $hostId, $hostName, $production));
            $findings->add($finding);

            return $finding;
        };
        $vulnerability = static fn (string $key, ?string $severity, bool $kev = false): FindingFacts => new FindingFacts(FindingKind::Vulnerability, $key, 'Title '.$key, 'pkg', '1', '2', $severity, null, $kev, null, null, null, null, ['GHSA-'.$key, 'CVE-'.$key]);
        $eol = static fn (string $cycle, string $end, string $level, ?string $suggested): FindingFacts => new FindingFacts(FindingKind::EndOfLife, $cycle, $cycle, 'php', '8', null, null, null, false, null, $level, new \DateTimeImmutable($end), null, [], $suggested);
        $add('shop', $shopEnv, $shop, $vulnerability('a', 'critical', true), '2026-11-10');
        $add('shop', $shopEnv, $shop, $vulnerability('b', 'high'), '2026-11-10');
        $add('shop', $shopEnv, $shop, $eol('php 8.2', '2026-12-31', 'soon', '8.5'), '2026-10-01');
        $add('blog', $blogEnv, $blog, $vulnerability('c', 'high'), '2026-11-12');
        $add('blog', $blogEnv, $blog, $vulnerability('d', null), '2026-09-01');
        $accepted = $add('blog', $blogEnv, $blog, $vulnerability('e', 'critical'), '2026-11-01');
        $fixed = $add('blog', $blogEnv, $blog, $vulnerability('f', 'medium'), '2026-11-01');
        $fixed->resolve(new \DateTimeImmutable('2026-11-11 10:00'));
        $add('blog', $blogEnv, $blog, new FindingFacts(FindingKind::Outdated, 'npm x', 'minor', 'x', '1', null, null, null, false, null, null, null, '2', []), '2026-11-01');
        $add('web-01', null, null, $eol('debian 11', '2026-08-31', 'expired', '13'), '2026-11-01', SubjectType::Host, $host);
        $add('loose', null, null, $vulnerability('g', 'low'), '2026-11-01');
        $acceptances->add(RiskAcceptance::accept(Uuid::v7(), $accepted, 'Accepted.', new \DateTimeImmutable('2027-01-01'), Uuid::v7(), $today));
        $this->flush();

        $read = new DbalFindingReadModel($this->manager->get()->getConnection());
        $team = $read->summary(new ExposureFilter(self::TEAM), $today);
        self::assertSame([['critical' => 1, 'high' => 2, 'medium' => 0, 'low' => 1, 'unknown' => 1], 1, ['upcoming' => 0, 'soon' => 1, 'imminent' => 0, 'expired' => 1], 1, 1], [$team->severities, $team->knownExploited, $team->eolLevels, $team->outdated, $team->accepted], 'Open and not accepted; the accepted counted apart; fixed ones nowhere.');
        self::assertCount(30, $team->trend);
        self::assertSame(['2026-10-14', '2026-11-12'], [$team->trend[0]['day'], $team->trend[29]['day']]);
        $days = array_column($team->trend, null, 'day');
        self::assertSame([['day' => '2026-11-10', 'opened' => 2, 'fixed' => 0], ['day' => '2026-11-11', 'opened' => 0, 'fixed' => 1], ['day' => '2026-11-12', 'opened' => 1, 'fixed' => 0]], [$days['2026-11-10'], $days['2026-11-11'], $days['2026-11-12']]);
        self::assertSame([['shop', 1, 1, 1, 3], ['blog', 0, 0, 1, 3], ['loose', 0, 0, 0, 1]], array_map(static fn (array $a): array => [$a['name'], $a['knownExploited'], $a['critical'], $a['high'], $a['total']], $team->topApplications), 'Exploited first, then critical, high; hosts are not applications.');
        self::assertSame([['debian 11', 'php', '2026-08-31', 'expired', '13', 1], ['php 8.2', 'php', '2026-12-31', 'soon', '8.5', 1]], array_map(static fn (array $c): array => [$c['title'], $c['product'], $c['endOfLife'], $c['level'], $c['suggested'], $c['subjects']], $team->calendar));

        $guest = $read->summary(new ExposureFilter(self::TEAM, [$shop->toRfc4122()]), $today);
        self::assertSame([['critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 0, 'unknown' => 0], ['shop']], [$guest->severities, array_column($guest->topApplications, 'name')], 'Its projects only, never the fleet.');
        self::assertSame(0, $read->summary(new ExposureFilter(self::TEAM, []), $today)->vulnerabilities());
        self::assertSame(['high' => 1, 'unknown' => 1], array_filter($read->summary(new ExposureFilter(self::TEAM, environmentId: $blogEnv->toRfc4122()), $today)->severities));
        self::assertSame(['expired' => 1], array_filter($read->summary(new ExposureFilter(self::TEAM, hostId: $host->toRfc4122()), $today)->eolLevels));
        self::assertSame(2, $read->summary(new ExposureFilter(self::TEAM, projectId: $shop->toRfc4122()), $today)->vulnerabilities());

        $titles = self::titles(...);
        self::assertSame(['Title a', 'Title b', 'Title c', 'Title g', 'Title d', 'debian 11', 'php 8.2', 'minor'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(), $today, new PageRequest())));
        self::assertSame(['Title e'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(status: 'accepted'), $today, new PageRequest())));
        self::assertSame(['Title f'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(status: 'fixed'), $today, new PageRequest())));
        self::assertCount(10, $read->list(new ExposureFilter(self::TEAM), new FindingCriteria(status: 'all'), $today, new PageRequest())->items);
        self::assertSame(['Title b', 'Title c'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria('vulnerability', 'high'), $today, new PageRequest())));
        self::assertSame(['debian 11', 'php 8.2'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria('eol'), $today, new PageRequest())));
        self::assertSame(['Title c', 'Title d', 'minor'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(projectId: $blog->toRfc4122()), $today, new PageRequest())));
        self::assertSame(['Title a', 'Title b', 'php 8.2'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(environmentId: $shopEnv->toRfc4122()), $today, new PageRequest())));
        self::assertSame(['Title a', 'Title b', 'debian 11', 'php 8.2'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(production: true), $today, new PageRequest())));
        self::assertSame(['Title c', 'Title g', 'Title d', 'minor'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(production: false), $today, new PageRequest())));
        self::assertSame(['Title a', 'Title b', 'debian 11', 'php 8.2'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(hostId: $host->toRfc4122()), $today, new PageRequest())), 'The host and the applications on it.');
        self::assertSame(['Title c', 'Title d', 'minor'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(hostId: $otherHost->toRfc4122()), $today, new PageRequest())));
        $production = $read->summary(new ExposureFilter(self::TEAM, production: true), $today);
        self::assertSame([['critical' => 1, 'high' => 1], ['soon' => 1, 'expired' => 1]], [array_filter($production->severities), array_filter($production->eolLevels)], 'The production dashboards.');
        self::assertSame([$host->toRfc4122() => 'web-01', $otherHost->toRfc4122() => 'web-02'], $read->hosts(new ExposureFilter(self::TEAM)), 'By name.');
        self::assertSame([$host->toRfc4122() => 'web-01'], $read->hosts(new ExposureFilter(self::TEAM, [$shop->toRfc4122()])), 'A guest: the hosts of its projects.');
        self::assertSame([$host->toRfc4122() => 'web-01'], $read->hosts(new ExposureFilter(self::TEAM, production: true)));
        self::assertSame(['Title a', 'Title b', 'Title c', 'debian 11', 'php 8.2'], self::titles(new Page($read->urgent(new ExposureFilter(self::TEAM), $today, 5), 0, 0)), 'Exploited, critical, high, then ends of life; never accepted, fixed or outdated.');
        self::assertSame(['Title a', 'Title b', 'Title c', 'debian 11', 'php 8.2', 'Title d', 'Title g'], self::titles(new Page($read->urgent(new ExposureFilter(self::TEAM), $today, 20), 0, 0)));
        self::assertSame(['Title a', 'Title b', 'debian 11', 'php 8.2'], self::titles(new Page($read->urgent(new ExposureFilter(self::TEAM, production: true), $today, 5), 0, 0)));
        self::assertEquals([
            $shop->toRfc4122() => ['knownExploited' => 1, 'critical' => 1, 'high' => 1, 'endsOfLife' => 1],
            $blog->toRfc4122() => ['knownExploited' => 0, 'critical' => 0, 'high' => 1, 'endsOfLife' => 0],
        ], $read->projectFigures(new ExposureFilter(self::TEAM), $today), 'Open, not accepted; the fleet left out.');
        self::assertSame([$shop->toRfc4122()], array_keys($read->projectFigures(new ExposureFilter(self::TEAM, production: true), $today)));
        self::assertSame(['Title a'], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(advisory: 'CVE-a'), $today, new PageRequest())), 'By an alias of its advisory.');
        self::assertSame([], $titles($read->list(new ExposureFilter(self::TEAM), new FindingCriteria(advisory: 'CVE-z'), $today, new PageRequest())));
        $first = $read->list(new ExposureFilter(self::TEAM), new FindingCriteria(), $today, new PageRequest())->items[0];
        self::assertSame([$host->toRfc4122(), 'web-01', true], [$first->hostId, $first->hostName, $first->production]);
        $searched = $read->list(new ExposureFilter(self::TEAM), new FindingCriteria(), $today, new PageRequest(search: 'web-01'));
        self::assertSame([['debian 11'], 1, 8, 'web-01', '13'], [$titles($searched), $searched->filtered, $searched->total, $searched->items[0]->subjectName, $searched->items[0]->suggested], 'The subject is searched too.');
    }

    /**
     * @param Page<FindingRow> $page
     *
     * @return list<string>
     */
    private static function titles(Page $page): array
    {
        return array_map(static fn (FindingRow $r): string => $r->title, $page->items);
    }

    private function flush(): void
    {
        $this->manager->get()->flush();
        $this->manager->get()->clear();
    }
}
