<?php

declare(strict_types=1);

namespace Tests\Exposure\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exposure\Application\Query\View\FindingRow;
use Exposure\Domain\Model\Finding;
use Exposure\Domain\Model\FindingFacts;
use Exposure\Domain\Model\FindingKind;
use Exposure\Domain\Model\RiskAcceptance;
use Exposure\Domain\Model\SubjectType;
use Exposure\Domain\Model\TeamExposure;
use Exposure\Infrastructure\Doctrine\DbalFindingReadModel;
use Exposure\Infrastructure\Doctrine\DoctrineFindingRepository;
use Exposure\Infrastructure\Doctrine\DoctrineRiskAcceptanceRepository;
use Exposure\Infrastructure\Doctrine\DoctrineTeamExposureRepository;
use Exposure\Infrastructure\Doctrine\ExposureManager;
use PHPUnit\Framework\Attributes\Test;
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
        $page = $read->ofSubject(self::TEAM, 'application', $subject->toRfc4122(), $now, new PageRequest());
        self::assertSame(['Title d', 'Title c', 'Title b', 'php 8.2', 'Title a'], array_map(static fn (FindingRow $r): string => $r->title, $page->items), 'Open first; vulnerabilities, the most severe and exploited first; then ends of life; fixed last.');
        self::assertSame([5, 5], [$page->filtered, $page->total]);
        self::assertSame(['2027-01-31', '2026-11-13'], [$page->items[1]->acceptedUntil?->format('Y-m-d'), $page->items[2]->acceptedUntil?->format('Y-m-d')]);
        self::assertNull($read->ofSubject(self::TEAM, 'application', $subject->toRfc4122(), new \DateTimeImmutable('2026-11-13'), new PageRequest())->items[2]->acceptedUntil, 'Expired on its day.');
        self::assertSame(['2026-12-31', 'soon', ['CVE-d'], true, 'fixed'], [$page->items[3]->endOfLife?->format('Y-m-d'), $page->items[3]->eolLevel, $page->items[0]->aliases, $page->items[0]->knownExploited, $page->items[4]->status]);
        self::assertNotNull($page->items[4]->fixedAt);

        $searched = $read->ofSubject(self::TEAM, 'application', $subject->toRfc4122(), $now, new PageRequest(search: 'cve-b'));
        self::assertSame([['Title b'], 1, 5], [array_map(static fn (FindingRow $r): string => $r->title, $searched->items), $searched->filtered, $searched->total]);
        self::assertSame('Title c', $read->find($high->id()->toRfc4122(), $now)?->title);
        self::assertNull($read->find(Uuid::v7()->toRfc4122(), $now));
        self::assertSame(['Not reachable.', 'Old.'], array_map(static fn (RiskAcceptance $a): string => $a->justification(), $acceptances->ofFinding($high->id())), 'The latest first.');
        self::assertSame($high->id()->toRfc4122(), $findings->find($high->id())?->id()->toRfc4122());

        $acceptances->deleteTeam($team);
        $this->flush();
        self::assertSame([], $acceptances->ofFinding($high->id()));
    }

    private function flush(): void
    {
        $this->manager->get()->flush();
        $this->manager->get()->clear();
    }
}
