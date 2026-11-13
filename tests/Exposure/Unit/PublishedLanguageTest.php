<?php

declare(strict_types=1);

namespace Tests\Exposure\Unit;

use Exposure\Application\EventHandler\PublishFindingChanges;
use Exposure\Application\Query\Contract\Handler\GetFindingDigestsHandler;
use Exposure\Application\Query\Contract\Handler\ListExpiringAcceptancesHandler;
use Exposure\Domain\Event\FindingEscalated;
use Exposure\Domain\Event\FindingFixed;
use Exposure\Domain\Event\FindingOpened;
use Exposure\Domain\Model\Finding;
use Exposure\Domain\Model\FindingFacts;
use Exposure\Domain\Model\FindingKind;
use Exposure\Domain\Model\RiskAcceptance;
use Exposure\Domain\Model\SubjectPlacement;
use Exposure\Domain\Model\SubjectType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Contract\Exposure\ExpiringAcceptance;
use Shared\Application\Contract\Exposure\FindingChanged;
use Shared\Application\Contract\Exposure\FindingDigest;
use Shared\Application\Contract\Exposure\GetFindingDigests;
use Shared\Application\Contract\Exposure\ListExpiringAcceptances;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Exposure\Support\InMemoryFindingReadModel;
use Tests\Exposure\Support\InMemoryFindings;
use Tests\Exposure\Support\InMemoryRiskAcceptances;
use Tests\Identity\Support\RecordingEventBus;

/**
 * What Exposure tells the other contexts: a finding opened, opened again, fixed, or an end of life coming closer;
 * what a notification says of a finding; the acceptances about to expire.
 */
final class PublishedLanguageTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string OTHER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';

    #[Test]
    public function it_should_escalate_an_end_of_life_coming_closer_only(): void
    {
        $now = new \DateTimeImmutable('2026-11-12');
        $eol = static fn (?string $level): FindingFacts => new FindingFacts(FindingKind::EndOfLife, 'php 8.2', 'php 8.2', 'php', '8.2', null, null, null, false, null, $level, null, null, []);
        $finding = Finding::detect(Uuid::v7(), Uuid::fromString(self::TEAM), SubjectType::Host, Uuid::v7(), $eol('upcoming'), $now);
        $finding->releaseEvents();
        $events = static fn (): array => array_map(static fn (object $e): string => $e instanceof FindingEscalated ? 'escalated '.$e->level : $e::class, $finding->releaseEvents());

        $finding->observe($eol('upcoming'), $now);
        self::assertSame([], $events(), 'Same level.');
        $finding->observe($eol('soon'), $now);
        self::assertSame(['escalated soon'], $events());
        $finding->observe($eol('expired'), $now);
        self::assertSame(['escalated expired'], $events(), 'A jump counts once.');
        $finding->observe($eol('imminent'), $now);
        self::assertSame([], $events(), 'Farther again (a corrected date): nothing.');
        $finding->resolve($now);
        $finding->releaseEvents();
        $finding->observe($eol('expired'), $now);
        self::assertSame([FindingOpened::class], $events(), 'Back from fixed: opened again, not escalated.');
        $finding->observe($eol(null), $now);
        self::assertSame([], $events());
    }

    #[Test]
    public function it_should_publish_the_changes_of_the_findings(): void
    {
        $events = new RecordingEventBus();
        $publish = new PublishFindingChanges($events, new MockClock('2026-05-04 10:00:00'));
        $publish(new FindingOpened('f1', self::TEAM, false));
        $publish(new FindingOpened('f2', self::TEAM, true));
        $publish(new FindingFixed('f3', self::TEAM));
        $publish(new FindingEscalated('f4', self::TEAM, 'soon'));
        self::assertEquals([
            new FindingChanged('f1', self::TEAM, FindingChanged::OPENED, '2026-05-04T10:00:00+00:00'), new FindingChanged('f2', self::TEAM, FindingChanged::REOPENED, '2026-05-04T10:00:00+00:00'),
            new FindingChanged('f3', self::TEAM, FindingChanged::FIXED, '2026-05-04T10:00:00+00:00'), new FindingChanged('f4', self::TEAM, FindingChanged::ESCALATED, '2026-05-04T10:00:00+00:00'),
        ], $events->published);
    }

    #[Test]
    public function it_should_describe_findings_and_list_the_acceptances_about_to_expire(): void
    {
        $now = new \DateTimeImmutable('2026-11-12 08:00');
        $findings = new InMemoryFindings();
        $acceptances = new InMemoryRiskAcceptances();
        $project = Uuid::v7();
        $environment = Uuid::v7();
        $finding = Finding::detect(Uuid::v7(), Uuid::fromString(self::TEAM), SubjectType::Application, Uuid::v7(), new FindingFacts(FindingKind::Vulnerability, 'GHSA-1 lodash', 'Command Injection', 'lodash', '4.17.20', '4.17.21', 'high', 7.2, true, 0.2, null, null, null, ['GHSA-1']), $now);
        $finding->place(new SubjectPlacement('shop / php', $environment, $project));
        $findings->add($finding);
        $foreign = Finding::detect(Uuid::v7(), Uuid::fromString(self::OTHER), SubjectType::Host, Uuid::v7(), new FindingFacts(FindingKind::Outdated, 'npm x', 'minor', 'x', '1', null, null, null, false, null, null, null, '2', []), $now);
        $findings->add($foreign);
        $acceptances->add(RiskAcceptance::accept(Uuid::v7(), $finding, 'Accepted.', new \DateTimeImmutable('2026-11-20'), Uuid::v7(), $now));

        $digests = new GetFindingDigestsHandler(new InMemoryFindingReadModel($findings, $acceptances), new MockClock($now))(new GetFindingDigests(self::TEAM, [$finding->id()->toRfc4122(), $foreign->id()->toRfc4122(), 'nope']));
        self::assertEquals([new FindingDigest(
            $finding->id()->toRfc4122(), self::TEAM, 'vulnerability', 'Command Injection', 'lodash', '4.17.20', '4.17.21', 'high', 7.2, true, 0.2, null, null, null, null, ['GHSA-1'], 'open', true,
            'application', $finding->subjectId()->toRfc4122(), 'shop / php', $project->toRfc4122(), $environment->toRfc4122(),
        )], $digests, 'Of the team only; accepted.');

        $other = Finding::detect(Uuid::v7(), Uuid::fromString(self::TEAM), SubjectType::Host, Uuid::v7(), new FindingFacts(FindingKind::Outdated, 'npm y', 'minor', 'y', '1', null, null, null, false, null, null, null, '2', []), $now);
        $findings->add($other);
        $both = new GetFindingDigestsHandler(new InMemoryFindingReadModel($findings, $acceptances), new MockClock($now))(new GetFindingDigests(self::TEAM, [$finding->id()->toRfc4122(), $other->id()->toRfc4122()]));
        self::assertEqualsCanonicalizing([$finding->id()->toRfc4122(), $other->id()->toRfc4122()], array_map(static fn (FindingDigest $d): string => $d->id, $both), 'Every finding asked.');

        $revoked = RiskAcceptance::accept(Uuid::v7(), $other, 'Revoked.', new \DateTimeImmutable('2026-11-18'), Uuid::v7(), $now);
        $revoked->revoke(Uuid::v7(), $now);
        $acceptances->add($revoked);
        $acceptances->add(RiskAcceptance::accept(Uuid::v7(), $other, 'Later.', new \DateTimeImmutable('2026-12-31'), Uuid::v7(), $now));
        $expiring = new ListExpiringAcceptancesHandler($acceptances)(new ListExpiringAcceptances(new \DateTimeImmutable('2026-11-13'), new \DateTimeImmutable('2026-11-20')));
        self::assertEquals([new ExpiringAcceptance($acceptances->acceptances[0]->id()->toRfc4122(), $finding->id()->toRfc4122(), self::TEAM, new \DateTimeImmutable('2026-11-20'))], $expiring, 'Running, in the window (its last day included).');
    }
}
