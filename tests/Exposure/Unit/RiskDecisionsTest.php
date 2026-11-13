<?php

declare(strict_types=1);

namespace Tests\Exposure\Unit;

use Exposure\Application\Command\Risk\AcceptRisk;
use Exposure\Application\Command\Risk\Handler\AcceptRiskHandler;
use Exposure\Application\Command\Risk\Handler\RevokeRiskAcceptanceHandler;
use Exposure\Application\Command\Risk\Handler\RiskDecision;
use Exposure\Application\Command\Risk\RevokeRiskAcceptance;
use Exposure\Application\Query\GetFinding;
use Exposure\Application\Query\Handler\FindingPlaces;
use Exposure\Application\Query\Handler\GetFindingHandler;
use Exposure\Application\Query\Handler\ListFindingsHandler;
use Exposure\Application\Query\ListFindings;
use Exposure\Application\Query\View\FindingRow;
use Exposure\Application\Security\FindingAccess;
use Exposure\Domain\Event\RiskAcceptanceRevoked;
use Exposure\Domain\Event\RiskAccepted;
use Exposure\Domain\Model\Finding;
use Exposure\Domain\Model\FindingFacts;
use Exposure\Domain\Model\FindingKind;
use Exposure\Domain\Model\InvalidRiskAcceptance;
use Exposure\Domain\Model\RiskAcceptance;
use Exposure\Domain\Model\SubjectType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Actor;
use Shared\Application\Audit\Target;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Inventory\GetApplicationPlacement;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Contract\Organization\VisibleProjects;
use Shared\Application\Query\PageRequest;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Exposure\Support\InMemoryFindingReadModel;
use Tests\Exposure\Support\InMemoryFindings;
use Tests\Exposure\Support\InMemoryRiskAcceptances;
use Tests\Organization\Support\FixedQueries;

use function Symfony\Component\String\u;

/**
 * A risk is accepted with a justification until a date within a year, by whom may decide on the finding: the project
 * of its application (`finding.accept_risk`), or the fleet for a host or an application not attached (`host.approve`).
 * Out of sight is 404; a running acceptance is replaced, revoked, or expires on its own.
 */
final class RiskDecisionsTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string OTHER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';
    private const string SHOP = '019a6b2c-3f00-7a11-8b22-3c44d55e6f01';
    private const string BLOG = '019a6b2c-3f00-7a11-8b22-3c44d55e6f02';
    private const string SHOP_ENV = '019a6b2c-4f00-7a11-8b22-3c44d55e6f01';
    private const string BLOG_ENV = '019a6b2c-4f00-7a11-8b22-3c44d55e6f02';
    private const string SHOP_APP = '019a6b31-6e00-7f12-a3b4-c5d6e7f80901';
    private const string BLOG_APP = '019a6b31-6e00-7f12-a3b4-c5d6e7f80902';
    private const string LOOSE_APP = '019a6b31-6e00-7f12-a3b4-c5d6e7f80903';
    private const string HOST = '019a6b2c-7f00-7a11-8b22-3c44d55e6f01';
    private const string ALICE = '019a6b2c-0f00-7a11-8b22-3c44d55e6f01';

    private InMemoryFindings $findings;
    private InMemoryRiskAcceptances $acceptances;
    private MockClock $clock;

    /** @var list<string> "permission project" granted, "" for none */
    public array $granted = [];

    private bool $guest = false;

    protected function setUp(): void
    {
        $this->findings = new InMemoryFindings();
        $this->acceptances = new InMemoryRiskAcceptances();
        $this->clock = new MockClock('2026-11-12 08:00:00', 'UTC');
        foreach ([[SubjectType::Application, self::SHOP_APP], [SubjectType::Application, self::BLOG_APP], [SubjectType::Application, self::LOOSE_APP], [SubjectType::Host, self::HOST]] as [$type, $subject]) {
            $this->findings->add(Finding::detect(Uuid::v7(), Uuid::fromString(self::TEAM), $type, Uuid::fromString($subject), self::facts(), $this->clock->now()));
        }
        $this->granted = ['finding.accept_risk '.self::SHOP, 'host.approve '];
    }

    #[Test]
    public function it_should_validate_an_acceptance(): void
    {
        $finding = $this->finding(self::SHOP_APP);
        $now = $this->clock->now();
        $accept = static fn (string $justification, string $until): RiskAcceptance => RiskAcceptance::accept(Uuid::v7(), $finding, $justification, new \DateTimeImmutable($until), Uuid::fromString(self::ALICE), $now);

        $acceptance = $accept('  Not reachable from the internet.  ', '2027-11-12 23:00');
        self::assertSame(['Not reachable from the internet.', '2027-11-12', self::ALICE, self::TEAM, $finding->id()->toRfc4122()], [$acceptance->justification(), $acceptance->expiresOn()->format('Y-m-d'), $acceptance->acceptedBy()->toRfc4122(), $acceptance->teamId()->toRfc4122(), $acceptance->findingId()->toRfc4122()]);
        self::assertEquals([$now, null, null], [$acceptance->acceptedAt(), $acceptance->revokedAt(), $acceptance->revokedBy()]);
        self::assertEquals([new RiskAccepted($acceptance->id()->toRfc4122(), $finding->id()->toRfc4122(), self::TEAM, '2027-11-12')], $acceptance->releaseEvents());
        self::assertSame([true, true, false, false], [$acceptance->isActiveOn($now), $acceptance->isActiveOn(new \DateTimeImmutable('2027-11-11 23:59')), $acceptance->isActiveOn(new \DateTimeImmutable('2027-11-12 00:00')), $acceptance->isActiveOn(new \DateTimeImmutable('2028-01-01'))], 'Ends on its expiry day.');
        self::assertSame('2026-11-13', $accept('x', '2026-11-13')->expiresOn()->format('Y-m-d'), 'Tomorrow at the earliest.');
        self::assertSame(2000, mb_strlen($accept(u('a')->repeat(2000)->toString(), '2027-01-01')->justification()));

        foreach ([['', '2027-01-01'], ['   ', '2027-01-01'], [u('a')->repeat(2001)->toString(), '2027-01-01'], ['x', '2026-11-12'], ['x', '2025-01-01'], ['x', '2027-11-13']] as [$justification, $until]) {
            try {
                $accept($justification, $until);
                self::fail($until);
            } catch (InvalidRiskAcceptance $refused) {
                self::assertSame('invalid_risk_acceptance', $refused->ruleKey());
            }
        }

        $by = Uuid::v7();
        $acceptance->revoke($by, $now);
        $acceptance->revoke(Uuid::v7(), $now->modify('+1 day'));
        self::assertEquals([$now, $by, false], [$acceptance->revokedAt(), $acceptance->revokedBy(), $acceptance->isActiveOn($now)]);
        self::assertEquals([new RiskAcceptanceRevoked($acceptance->id()->toRfc4122(), $finding->id()->toRfc4122(), self::TEAM)], $acceptance->releaseEvents(), 'Revoked once.');
    }

    #[Test]
    public function it_should_accept_replace_and_revoke_with_the_permission_of_the_project(): void
    {
        $finding = $this->finding(self::SHOP_APP)->id()->toRfc4122();
        $this->accept()(new AcceptRisk($finding, 'Not reachable.', '2027-01-31'));
        self::assertCount(1, $this->acceptances->acceptances);
        $this->clock->modify('+1 day');
        $this->accept()(new AcceptRisk($finding, 'Still not reachable.', '2027-03-31'));
        $history = $this->acceptances->ofFinding(Uuid::fromString($finding));
        self::assertCount(2, $history);
        [$second, $first] = $history;
        self::assertEquals([$this->clock->now(), true], [$first->revokedAt(), $second->isActiveOn($this->clock->now())], 'Replaced.');
        self::assertSame([self::ALICE, 'Still not reachable.'], [$first->revokedBy()?->toRfc4122(), $second->justification()]);

        new RevokeRiskAcceptanceHandler($this->decision(), $this->acceptances, $this->actor(), $this->clock)(new RevokeRiskAcceptance($finding));
        self::assertFalse($second->isActiveOn($this->clock->now()));

        $this->assertRefused(InvalidRiskAcceptance::class, fn () => $this->accept()(new AcceptRisk($finding, 'x', 'next year')));
        $this->assertRefused(InvalidRiskAcceptance::class, fn () => $this->accept()(new AcceptRisk($finding, 'x', '2027-02-30')));
        $this->assertRefused(PermissionDenied::class, fn () => $this->accept()(new AcceptRisk($this->finding(self::BLOG_APP)->id()->toRfc4122(), 'x', '2027-01-31')), 'No finding.accept_risk on Blog.');
        $this->accept()(new AcceptRisk($this->finding(self::HOST)->id()->toRfc4122(), 'x', '2027-01-31'));
        $this->accept()(new AcceptRisk($this->finding(self::LOOSE_APP)->id()->toRfc4122(), 'x', '2027-01-31'));
        $this->granted = ['finding.accept_risk '.self::SHOP];
        $this->assertRefused(PermissionDenied::class, fn () => $this->accept()(new AcceptRisk($this->finding(self::HOST)->id()->toRfc4122(), 'x', '2027-01-31')), 'A host: host.approve.');
        $this->expectRefusalMessage('Permission "host.approve" required.', fn () => $this->accept()(new AcceptRisk($this->finding(self::HOST)->id()->toRfc4122(), 'x', '2027-01-31')));
        $this->expectRefusalMessage('Permission "finding.accept_risk" required.', fn () => $this->accept()(new AcceptRisk($this->finding(self::BLOG_APP)->id()->toRfc4122(), 'x', '2027-01-31')));
        $this->assertRefused(ResourceNotFound::class, fn () => $this->accept()(new AcceptRisk('nope', 'x', '2027-01-31')));
        $this->assertRefused(ResourceNotFound::class, fn () => $this->accept()(new AcceptRisk(Uuid::v7()->toRfc4122(), 'x', '2027-01-31')));

        $this->guest = true;
        $this->granted = ['finding.accept_risk '.self::SHOP, 'host.approve '];
        foreach ([self::BLOG_APP, self::LOOSE_APP, self::HOST] as $hidden) {
            $this->assertRefused(ResourceNotFound::class, fn () => $this->accept()(new AcceptRisk($this->finding($hidden)->id()->toRfc4122(), 'x', '2027-01-31')), $hidden);
        }

        $command = new AcceptRisk($finding, 'x', '2027-01-31');
        self::assertSame(['exposure.accept_risk', ['expires_on' => '2027-01-31']], [$command->auditAction()->name, $command->auditContext()->values]);
        self::assertEquals(Target::of('finding', $finding), $command->auditTarget());
        self::assertSame(['exposure.revoke_risk_acceptance', []], [new RevokeRiskAcceptance($finding)->auditAction()->name, new RevokeRiskAcceptance($finding)->auditContext()->values]);
        self::assertEquals(Target::of('finding', $finding), new RevokeRiskAcceptance($finding)->auditTarget());
    }

    #[Test]
    public function it_should_show_the_findings_the_actor_sees(): void
    {
        $read = new InMemoryFindingReadModel($this->findings, $this->acceptances);
        $list = fn (string $type, string $subject, string $team = self::TEAM): array => array_map(static fn (FindingRow $r): string => $r->subjectId, (new ListFindingsHandler($this->access(), $read, $this->clock, $this->places()))(new ListFindings($team, $type, $subject, new PageRequest()))->items);
        self::assertSame([self::SHOP_APP], $list('application', self::SHOP_APP));
        self::assertSame([self::HOST], $list('host', self::HOST));
        self::assertSame([self::LOOSE_APP], $list('application', self::LOOSE_APP));
        $this->assertRefused(ResourceNotFound::class, static fn () => $list('image', self::SHOP_APP));
        $this->assertRefused(ResourceNotFound::class, static fn () => $list('host', 'nope'));
        $this->assertRefused(ResourceNotFound::class, static fn () => $list('application', self::SHOP_APP, self::OTHER), 'Another team.');
        $this->assertRefused(ResourceNotFound::class, static fn () => $list('application', '019a6b31-6e00-7f12-a3b4-c5d6e7f80909'), 'No such application.');

        $finding = $this->finding(self::SHOP_APP)->id()->toRfc4122();
        $this->accept()(new AcceptRisk($finding, 'Not reachable.', '2027-01-31'));
        $get = fn (string $id, string $team = self::TEAM) => (new GetFindingHandler($this->access(), $read, $this->acceptances, $this->accounts(), $this->clock, $this->places()))(new GetFinding($team, $id));
        $view = $get($finding);
        self::assertSame([true, '2027-01-31', 'alice@example.com', true], [$view->mayDecide, $view->finding->acceptedUntil?->format('Y-m-d'), $view->acceptances[0]->acceptedBy, $view->runningAcceptance()?->active]);
        self::assertTrue($view->finding->isAccepted());
        self::assertSame([false, null, []], [$get($this->finding(self::BLOG_APP)->id()->toRfc4122())->mayDecide, $get($this->finding(self::BLOG_APP)->id()->toRfc4122())->runningAcceptance(), $get($this->finding(self::BLOG_APP)->id()->toRfc4122())->acceptances]);
        $this->assertRefused(ResourceNotFound::class, static fn () => $get($finding, self::OTHER));
        $this->assertRefused(ResourceNotFound::class, static fn () => $get('nope'));

        $this->clock->modify('+1 year');
        $expired = $get($finding);
        self::assertSame([null, false, false], [$expired->finding->acceptedUntil, $expired->finding->isAccepted(), $expired->acceptances[0]->active], 'Expired: open again.');

        $this->guest = true;
        self::assertSame([self::SHOP_APP], $list('application', self::SHOP_APP));
        foreach ([['application', self::BLOG_APP], ['application', self::LOOSE_APP], ['host', self::HOST]] as [$type, $subject]) {
            $this->assertRefused(ResourceNotFound::class, static fn () => $list($type, $subject), $subject);
        }
    }

    private function accept(): AcceptRiskHandler
    {
        return new AcceptRiskHandler($this->decision(), $this->acceptances, $this->actor(), $this->clock);
    }

    private function decision(): RiskDecision
    {
        return new RiskDecision($this->findings, $this->access());
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
            GetApplicationPlacement::class => static fn (object $q): ?array => $q instanceof GetApplicationPlacement ? match ($q->applicationId) {
                self::SHOP_APP => ['teamId' => self::TEAM, 'environmentId' => self::SHOP_ENV],
                self::BLOG_APP => ['teamId' => self::TEAM, 'environmentId' => self::BLOG_ENV],
                self::LOOSE_APP => ['teamId' => self::TEAM, 'environmentId' => null],
                default => null,
            } : null,
            ListTeamEnvironments::class => static fn (): array => [new TeamEnvironment(self::BLOG_ENV, 'production', self::BLOG, 'Blog'), new TeamEnvironment(self::SHOP_ENV, 'production', self::SHOP, 'Shop')],
        ]);
        $checker = new readonly class($this) implements PermissionChecker {
            public function __construct(private RiskDecisionsTest $test)
            {
            }

            public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
            {
                return RiskDecisionsTest::TEAM_ID === $teamId && \in_array($permission->value.' '.$projectId, $this->test->granted, true);
            }
        };

        return new FindingAccess($queries, $checker);
    }

    public const string TEAM_ID = self::TEAM;

    private function accounts(): FixedQueries
    {
        return new FixedQueries([FindAccounts::class => static fn (): array => [self::ALICE => new AccountView(self::ALICE, 'alice@example.com', true)]]);
    }

    private function actor(): CurrentActor
    {
        return new readonly class implements CurrentActor {
            public function actor(): Actor
            {
                return Actor::user(Uuid::fromString(RiskDecisionsTest::ALICE_ID));
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }
        };
    }

    public const string ALICE_ID = self::ALICE;

    private function finding(string $subject): Finding
    {
        return array_find($this->findings->findings, static fn (Finding $f): bool => $f->subjectId()->toRfc4122() === $subject) ?? throw new \LogicException($subject);
    }

    private static function facts(): FindingFacts
    {
        return new FindingFacts(FindingKind::Vulnerability, 'GHSA-1 lodash', 'Command Injection', 'lodash', '4.17.20', '4.17.21', 'high', 7.2, false, null, null, null, null, ['GHSA-1']);
    }

    private function expectRefusalMessage(string $message, \Closure $call): void
    {
        try {
            $call();
            self::fail($message);
        } catch (PermissionDenied $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function assertRefused(string $class, \Closure $call, string $message = ''): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, $message.' '.$e->getMessage());

            return;
        }

        self::fail('Expected '.$class.'. '.$message);
    }
}
