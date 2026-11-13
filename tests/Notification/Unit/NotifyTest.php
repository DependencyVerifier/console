<?php

declare(strict_types=1);

namespace Tests\Notification\Unit;

use Notification\Application\Command\Notify\CheckNotifications;
use Notification\Application\Command\Notify\Handler\CheckNotificationsHandler;
use Notification\Application\Command\Notify\Handler\NotifyAgentAwaitingApprovalHandler;
use Notification\Application\Command\Notify\Handler\NotifyFindingChangeHandler;
use Notification\Application\Command\Notify\NotifyAgentAwaitingApproval;
use Notification\Application\Command\Notify\NotifyFindingChange;
use Notification\Application\Content\NotificationContent;
use Notification\Application\EventHandler\QueueNotifications;
use Notification\Application\Notice\NoticeWriter;
use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\Notice;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Model\RecipientInvitation;
use Notification\Domain\Model\RuleThresholds;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Model\WebhookChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Contract\Exposure\ExpiringAcceptance;
use Shared\Application\Contract\Exposure\FindingChanged;
use Shared\Application\Contract\Exposure\FindingDigest;
use Shared\Application\Contract\Exposure\GetFindingDigests;
use Shared\Application\Contract\Exposure\ListExpiringAcceptances;
use Shared\Application\Contract\Fleet\AgentAwaitingApproval;
use Shared\Application\Contract\Fleet\ListSilentAgents;
use Shared\Application\Contract\Fleet\SilentAgent;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\FilterRecipients;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Notification\Support\InMemoryDeliveries;
use Tests\Notification\Support\InMemoryNotices;
use Tests\Notification\Support\InMemoryRecipientInvitations;
use Tests\Notification\Support\InMemoryRules;
use Tests\Notification\Support\InMemorySubscriptions;
use Tests\Notification\Support\InMemoryWebhooks;
use Tests\Organization\Support\FixedQueries;
use Tests\Organization\Support\RecordingCommands;

/**
 * An event to the outbox: the rules of its scope retaining it, one notice each (never twice), one delivery per
 * webhook of the rule and per subscriber who is active, sees it, and did not turn it off.
 */
final class NotifyTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string OTHER_TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';
    private const string PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f11';
    private const string ENV = '019a6b2c-1f00-7a11-8b22-3c44d55e6f21';
    private const string FINDING = '019a6b2c-1f00-7a11-8b22-3c44d55e6f41';
    private const string ALICE = '019a6b2c-1f00-7a11-8b22-3c44d55e6f51';
    private const string BOB = '019a6b2c-1f00-7a11-8b22-3c44d55e6f52';
    private const string CAROL = '019a6b2c-1f00-7a11-8b22-3c44d55e6f53';
    private const string DAN = '019a6b2c-1f00-7a11-8b22-3c44d55e6f54';
    private const string AT = '2026-11-12T10:00:00+00:00';

    private InMemoryRules $rules;
    private InMemoryWebhooks $webhooks;
    private InMemorySubscriptions $subscriptions;
    private InMemoryNotices $notices;
    private InMemoryDeliveries $deliveries;
    private MockClock $clock;
    private ?FindingDigest $digest = null;
    /** @var list<SilentAgent> */
    private array $silent = [];
    /** @var list<ExpiringAcceptance> */
    private array $expiring = [];
    private FixedQueries $queries;

    protected function setUp(): void
    {
        $this->rules = new InMemoryRules();
        $this->webhooks = new InMemoryWebhooks();
        $this->subscriptions = new InMemorySubscriptions();
        $this->notices = new InMemoryNotices();
        $this->deliveries = new InMemoryDeliveries();
        $this->clock = new MockClock('2026-11-12 10:00:00');
        $this->queries = new FixedQueries([
            GetFindingDigests::class => fn (object $q): array => null !== $this->digest && $q instanceof GetFindingDigests && [$this->digest->id] === $q->findingIds && $this->digest->teamId === $q->teamId ? [$this->digest] : [],
            // Bob does not see the project; Carol sees it but her account is deactivated.
            FilterRecipients::class => static fn (object $q): array => $q instanceof FilterRecipients ? array_values(array_diff($q->userIds, [self::BOB])) : [],
            FindAccounts::class => static fn (object $q): array => $q instanceof FindAccounts ? array_combine($q->userIds, array_map(static fn (string $id): AccountView => new AccountView($id, $id.'@example.com', self::CAROL !== $id), $q->userIds)) : [],
            ListSilentAgents::class => fn (object $q): array => $q instanceof ListSilentAgents ? array_values(array_filter($this->silent, static fn (SilentAgent $a): bool => $a->lastSeenAt < $q->since)) : [],
            ListExpiringAcceptances::class => fn (object $q): array => $this->expiring,
        ]);
    }

    #[Test]
    public function it_should_deliver_a_vulnerability_to_the_webhooks_and_subscribers_of_its_project(): void
    {
        $hook = $this->webhook();
        $foreign = WebhookChannel::register(Uuid::v7(), Uuid::fromString(self::OTHER_TEAM), 'Foreign', 'https://other.example.com', 's', $this->clock->now());
        $this->webhooks->add($foreign);
        $rule = $this->rule([NotificationEvent::VulnerabilityOpened], webhooks: [$hook->id(), $foreign->id(), Uuid::v7()]);
        $this->subscribe(self::ALICE, Frequency::Immediate);
        $this->subscribe(self::BOB, Frequency::Immediate);
        $this->subscribe(self::CAROL, Frequency::Immediate);
        $this->subscribe(self::DAN, Frequency::Weekly);
        $this->subscribe(Uuid::v7()->toRfc4122(), Frequency::Off);
        $this->subscribe(Uuid::v7()->toRfc4122(), Frequency::Immediate, project: null);
        $this->digest = $this->digest();

        $this->findingChange(FindingChanged::OPENED);

        self::assertCount(1, $this->notices->notices);
        $notice = $this->notices->notices[0];
        self::assertSame($rule->id(), $notice->ruleId());
        self::assertSame(NotificationEvent::VulnerabilityOpened, $notice->event());
        self::assertSame('finding:'.self::FINDING.':opened:'.self::AT, $notice->dedupeKey());
        self::assertEquals($this->clock->now(), $notice->createdAt());
        self::assertSame([
            'change' => 'opened',
            'finding' => [
                'id' => self::FINDING, 'kind' => 'vulnerability', 'title' => 'RCE in twig', 'package' => 'twig/twig', 'installed' => '3.0.0', 'fixed' => '3.0.1',
                'severity' => 'critical', 'cvss' => 9.8, 'known_exploited' => true, 'epss' => 0.5, 'eol_level' => null, 'end_of_life' => null,
                'suggested' => null, 'latest' => '3.9.0', 'aliases' => ['GHSA-1', 'CVE-1'],
            ],
            'subject' => ['type' => 'application', 'id' => 'app-1', 'name' => 'shop'],
            'project_id' => self::PROJECT,
            'environment_id' => self::ENV,
        ], $notice->content());
        self::assertSame([
            ['webhook', $hook->id()->toRfc4122(), 'immediate'],
            ['email', self::ALICE, 'immediate'],
            ['email', self::DAN, 'weekly'],
        ], $this->deliveries());
        self::assertEquals([new FilterRecipients(self::TEAM, self::PROJECT, [self::ALICE, self::BOB, self::CAROL, self::DAN]), new FindAccounts([self::ALICE, self::CAROL, self::DAN])], \array_slice($this->queries->asked, 1));
    }

    #[Test]
    public function it_should_tell_an_occurrence_once_per_rule(): void
    {
        $first = $this->rule([NotificationEvent::VulnerabilityOpened]);
        $second = $this->rule([NotificationEvent::VulnerabilityOpened], webhooks: [$this->webhook()->id()]);
        $this->subscribe(self::ALICE, Frequency::Daily);
        $this->digest = $this->digest();

        $this->findingChange(FindingChanged::OPENED);
        $this->findingChange(FindingChanged::OPENED);

        self::assertSame([$first->id(), $second->id()], array_map(static fn (Notice $n): Uuid => $n->ruleId(), $this->notices->notices));
        self::assertCount(3, $this->deliveries->deliveries);
        self::assertCount(1, $this->asked(FilterRecipients::class), 'Read once for both rules; not again for a notice already written.');

        $this->findingChange(FindingChanged::OPENED, '2026-11-13T10:00:00+00:00');
        self::assertCount(4, $this->notices->notices, 'Another occurrence.');
    }

    #[Test]
    public function it_should_not_ask_who_sees_when_nobody_subscribed(): void
    {
        $this->rule([NotificationEvent::VulnerabilityOpened]);
        $this->subscribe(self::ALICE, Frequency::Off);
        $this->digest = $this->digest();

        $this->findingChange(FindingChanged::OPENED);

        self::assertCount(1, $this->notices->notices);
        self::assertSame([], $this->deliveries->deliveries);
        self::assertCount(1, $this->queries->asked);
    }

    #[Test]
    public function it_should_keep_a_rule_to_its_scope_and_thresholds(): void
    {
        $this->rule([NotificationEvent::VulnerabilityOpened], project: null);
        $this->rule([NotificationEvent::VulnerabilityOpened], thresholds: new RuleThresholds(minCvss: 9.9));
        $this->rule([NotificationEvent::VulnerabilityFixed]);
        $kept = $this->rule([NotificationEvent::VulnerabilityOpened], thresholds: new RuleThresholds('critical', 9.8));
        $this->digest = $this->digest();

        $this->findingChange(FindingChanged::OPENED);

        self::assertSame([$kept->id()], array_map(static fn (Notice $n): Uuid => $n->ruleId(), $this->notices->notices));
    }

    #[Test]
    public function it_should_deliver_a_fleet_finding_to_the_fleet(): void
    {
        $this->rule([NotificationEvent::EndOfLife], project: null);
        $this->subscribe(self::ALICE, Frequency::Daily, project: null);
        $this->digest = $this->digest(kind: 'eol', project: null, environment: null);

        $this->findingChange(FindingChanged::ESCALATED);

        self::assertSame(NotificationEvent::EndOfLife, $this->notices->notices[0]->event());
        self::assertSame([['email', self::ALICE, 'daily']], $this->deliveries());
        self::assertEquals(new FilterRecipients(self::TEAM, null, [self::ALICE]), $this->queries->asked[1]);
    }

    /**
     * @return iterable<string, array{string, string, ?NotificationEvent}>
     */
    public static function changes(): iterable
    {
        yield 'vulnerability opened' => ['vulnerability', FindingChanged::OPENED, NotificationEvent::VulnerabilityOpened];
        yield 'vulnerability reopened' => ['vulnerability', FindingChanged::REOPENED, NotificationEvent::VulnerabilityOpened];
        yield 'vulnerability fixed' => ['vulnerability', FindingChanged::FIXED, NotificationEvent::VulnerabilityFixed];
        yield 'end of life opened' => ['eol', FindingChanged::OPENED, NotificationEvent::EndOfLife];
        yield 'end of life escalated' => ['eol', FindingChanged::ESCALATED, NotificationEvent::EndOfLife];
        yield 'end of life fixed' => ['eol', FindingChanged::FIXED, null];
        yield 'outdated opened' => ['outdated', FindingChanged::OPENED, NotificationEvent::Outdated];
        yield 'outdated reopened' => ['outdated', FindingChanged::REOPENED, NotificationEvent::Outdated];
        yield 'outdated fixed' => ['outdated', FindingChanged::FIXED, null];
        yield 'outdated escalated' => ['outdated', FindingChanged::ESCALATED, null];
        yield 'unknown kind' => ['license', FindingChanged::OPENED, null];
    }

    #[Test]
    #[DataProvider('changes')]
    public function it_should_tell_what_a_change_means(string $kind, string $change, ?NotificationEvent $event): void
    {
        $this->rule(NotificationEvent::cases());
        $this->digest = $this->digest(kind: $kind);

        $this->findingChange($change);

        self::assertSame(null === $event ? [] : [$event], array_map(static fn (Notice $n): NotificationEvent => $n->event(), $this->notices->notices));
    }

    #[Test]
    public function it_should_be_silent_on_an_accepted_or_gone_finding(): void
    {
        $this->rule(NotificationEvent::cases());
        $this->digest = $this->digest(accepted: true);
        $this->findingChange(FindingChanged::OPENED);

        $this->digest = null;
        $this->findingChange(FindingChanged::OPENED);

        self::assertSame([], $this->notices->notices);
    }

    #[Test]
    public function it_should_deliver_an_agent_awaiting_approval_to_the_fleet(): void
    {
        $this->rule([NotificationEvent::AgentPending], project: null, webhooks: [$hook = $this->webhook()->id()], email: false);
        $this->rule([NotificationEvent::Outdated]);

        new NotifyAgentAwaitingApprovalHandler($this->writer())(new NotifyAgentAwaitingApproval('agent-1', self::TEAM, 'web-01'));
        new NotifyAgentAwaitingApprovalHandler($this->writer())(new NotifyAgentAwaitingApproval('agent-1', self::TEAM, 'web-01'));

        self::assertCount(1, $this->notices->notices);
        self::assertSame(NotificationEvent::AgentPending, $this->notices->notices[0]->event());
        self::assertSame('agent-pending:agent-1', $this->notices->notices[0]->dedupeKey());
        self::assertSame(['agent' => ['id' => 'agent-1', 'hostname' => 'web-01']], $this->notices->notices[0]->content());
        self::assertSame([['webhook', $hook->toRfc4122(), 'immediate']], $this->deliveries());
    }

    #[Test]
    public function it_should_tell_of_the_silent_agents_once_per_silence(): void
    {
        $this->rule([NotificationEvent::AgentSilent], project: null, thresholds: new RuleThresholds(silentHours: 24));
        $this->rule([NotificationEvent::AgentSilent], project: null, thresholds: new RuleThresholds(silentHours: 48));
        $this->rule([NotificationEvent::AgentSilent], project: null, team: self::OTHER_TEAM, thresholds: new RuleThresholds(silentHours: 72));
        $now = $this->clock->now();
        $this->silent = [
            new SilentAgent('a-30h', self::TEAM, 'web-01', $now->modify('-30 hours -59 minutes')),
            new SilentAgent('a-50h', self::TEAM, 'web-02', $now->modify('-48 hours')),
            new SilentAgent('a-23h', self::TEAM, 'web-03', $now->modify('-23 hours -59 minutes')),
            new SilentAgent('a-almost-48h', self::TEAM, 'web-04', $now->modify('-48 hours +1 second')),
            new SilentAgent('a-elsewhere', '019a6b2c-1f00-7a11-8b22-3c44d55e6f09', 'x', $now->modify('-100 hours')),
            new SilentAgent('a-other-30h', self::OTHER_TEAM, 'db-01', $now->modify('-30 hours')),
        ];

        $this->check();
        $this->check();

        self::assertEquals([new ListSilentAgents($now->modify('-24 hours')), new ListSilentAgents($now->modify('-24 hours'))], $this->asked(ListSilentAgents::class), 'Once per check, since the shortest threshold.');
        self::assertSame([
            'agent-silent:a-30h:'.$now->modify('-30 hours -59 minutes')->format(\DATE_ATOM),
            'agent-silent:a-50h:'.$now->modify('-48 hours')->format(\DATE_ATOM),
            'agent-silent:a-50h:'.$now->modify('-48 hours')->format(\DATE_ATOM),
            'agent-silent:a-almost-48h:'.$now->modify('-48 hours +1 second')->format(\DATE_ATOM),
        ], array_map(static fn (Notice $n): string => $n->dedupeKey(), $this->notices->notices), 'a-30h and a-almost-48h: the 24 h rule only; a-50h: both; the other team: not 72 h yet.');
        self::assertSame(['agent' => ['id' => 'a-30h', 'hostname' => 'web-01', 'last_seen_at' => '2026-11-11T03:01:00+00:00']], $this->notices->notices[0]->content());

        $this->silent = [new SilentAgent('a-30h', self::TEAM, 'web-01', $now->modify('-25 hours'))];
        $this->check();
        self::assertCount(5, $this->notices->notices, 'Silent again after a contact: another silence.');
    }

    #[Test]
    public function it_should_not_list_the_agents_without_a_rule(): void
    {
        $this->rule([NotificationEvent::AgentPending], project: null);

        $this->check();

        self::assertSame([ListExpiringAcceptances::class], array_map(static fn (object $q): string => $q::class, $this->queries->asked));
    }

    #[Test]
    public function it_should_tell_of_the_acceptances_expiring_within_a_week(): void
    {
        $this->rule([NotificationEvent::AcceptanceExpiring]);
        $this->rule([NotificationEvent::AcceptanceExpiring], environment: Uuid::v7()->toRfc4122());
        $this->clock->modify('2026-11-12 23:30:00');
        $this->digest = $this->digest();
        $this->expiring = [
            new ExpiringAcceptance('acc-2', Uuid::v7()->toRfc4122(), self::TEAM, new \DateTimeImmutable('2026-11-15')),
            new ExpiringAcceptance('acc-1', self::FINDING, self::TEAM, new \DateTimeImmutable('2026-11-19')),
        ];

        $this->check();
        $this->check();

        self::assertEquals(new ListExpiringAcceptances(new \DateTimeImmutable('2026-11-12'), new \DateTimeImmutable('2026-11-19')), $this->queries->asked[0]);
        self::assertCount(1, $this->notices->notices, 'Once; the finding of acc-2 is gone; the other rule watches another environment.');
        self::assertSame('acceptance-expiring:acc-1', $this->notices->notices[0]->dedupeKey());
        self::assertSame(NotificationEvent::AcceptanceExpiring, $this->notices->notices[0]->event());
        self::assertSame([
            'acceptance' => ['id' => 'acc-1', 'expires_on' => '2026-11-19'],
            'finding' => ['id' => self::FINDING, 'kind' => 'vulnerability', 'title' => 'RCE in twig', 'package' => 'twig/twig'],
            'subject' => ['type' => 'application', 'id' => 'app-1', 'name' => 'shop'],
            'project_id' => self::PROJECT,
            'environment_id' => self::ENV,
        ], $this->notices->notices[0]->content());
    }

    #[Test]
    public function it_should_queue_what_the_other_contexts_publish(): void
    {
        $commands = new RecordingCommands();
        $queue = new QueueNotifications($commands);
        $queue(new FindingChanged(self::FINDING, self::TEAM, FindingChanged::FIXED, self::AT));
        $queue(new AgentAwaitingApproval('agent-1', self::TEAM, 'web-01'));

        self::assertEquals([new NotifyFindingChange(self::FINDING, self::TEAM, FindingChanged::FIXED, self::AT), new NotifyAgentAwaitingApproval('agent-1', self::TEAM, 'web-01')], $commands->dispatched);
    }

    #[Test]
    public function it_should_erase_a_team_and_keep_the_rules_of_a_project(): void
    {
        $hook = $this->webhook();
        $this->rule([NotificationEvent::VulnerabilityOpened], environment: self::ENV, webhooks: [$hook->id()]);
        $this->rule([NotificationEvent::VulnerabilityOpened], team: self::OTHER_TEAM, project: Uuid::v7()->toRfc4122());
        $this->subscribe(self::ALICE, Frequency::Immediate);
        $this->digest = $this->digest();
        $this->findingChange(FindingChanged::OPENED);
        $invitations = new InMemoryRecipientInvitations();
        $invitations->add(RecipientInvitation::issue(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), 'ops@example.com', Frequency::Daily, Uuid::v7(), 't', 'h:t', $this->clock->now()));
        $content = new NotificationContent($this->rules, $this->webhooks, $this->subscriptions, $this->notices, $this->deliveries, $invitations);

        self::assertTrue($content->projectHasContent(self::PROJECT));
        self::assertTrue($content->environmentHasContent(self::ENV));
        self::assertFalse($content->projectHasContent(Uuid::v7()->toRfc4122()));
        self::assertFalse($content->environmentHasContent(Uuid::v7()->toRfc4122()));

        $content->eraseTeam(self::TEAM);
        self::assertSame([], $this->webhooks->webhooks);
        self::assertCount(1, $this->rules->rules);
        self::assertSame([], $this->subscriptions->subscriptions);
        self::assertSame([], $this->notices->notices);
        self::assertSame([], $this->deliveries->deliveries);
        self::assertSame([], $invitations->invitations);
    }

    private function findingChange(string $change, string $at = self::AT): void
    {
        new NotifyFindingChangeHandler($this->queries, $this->writer())(new NotifyFindingChange(self::FINDING, self::TEAM, $change, $at));
    }

    private function check(): void
    {
        new CheckNotificationsHandler($this->rules, $this->queries, $this->writer(), $this->clock)(new CheckNotifications());
    }

    private function writer(): NoticeWriter
    {
        return new NoticeWriter($this->rules, $this->webhooks, $this->subscriptions, $this->notices, $this->deliveries, $this->queries, $this->clock);
    }

    /**
     * @param list<NotificationEvent> $events
     * @param list<Uuid>              $webhooks
     */
    private function rule(array $events, string $team = self::TEAM, ?string $project = self::PROJECT, ?string $environment = null, ?RuleThresholds $thresholds = null, array $webhooks = [], bool $email = true): NotificationRule
    {
        $rule = NotificationRule::define(Uuid::v7(), Uuid::fromString($team), null === $project ? null : Uuid::fromString($project), null === $environment ? null : Uuid::fromString($environment), 'Rule', $events, $thresholds ?? new RuleThresholds(), $webhooks, $email, $this->clock->now());
        $this->rules->add($rule);

        return $rule;
    }

    private function webhook(): WebhookChannel
    {
        $webhook = WebhookChannel::register(Uuid::v7(), Uuid::fromString(self::TEAM), 'Hook', 'https://hooks.example.com', 'sealed', $this->clock->now());
        $this->webhooks->add($webhook);

        return $webhook;
    }

    private function subscribe(string $user, Frequency $frequency, ?string $project = self::PROJECT): void
    {
        $this->subscriptions->add(Subscription::subscribe(Uuid::v7(), Uuid::fromString(self::TEAM), null === $project ? null : Uuid::fromString($project), Uuid::fromString($user), $frequency, $this->clock->now()));
    }

    private function digest(string $kind = 'vulnerability', ?string $project = self::PROJECT, ?string $environment = self::ENV, bool $accepted = false): FindingDigest
    {
        return new FindingDigest(self::FINDING, self::TEAM, $kind, 'RCE in twig', 'twig/twig', '3.0.0', '3.0.1', 'critical', 9.8, true, 0.5, null, null, null, '3.9.0', ['GHSA-1', 'CVE-1'], 'open', $accepted, 'application', 'app-1', 'shop', $project, $environment);
    }

    /**
     * @param class-string $class
     *
     * @return list<object>
     */
    private function asked(string $class): array
    {
        return array_values(array_filter($this->queries->asked, static fn (object $q): bool => $q instanceof $class));
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function deliveries(): array
    {
        return array_map(static fn (Delivery $d): array => [$d->channel()->value, $d->target(), $d->frequency()->value], $this->deliveries->deliveries);
    }
}
