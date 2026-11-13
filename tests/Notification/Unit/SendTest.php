<?php

declare(strict_types=1);

namespace Tests\Notification\Unit;

use Notification\Application\Command\Send\Handler\SendDeliveryHandler;
use Notification\Application\Command\Send\Handler\SendDigestHandler;
use Notification\Application\Command\Send\Handler\UnsubscribeHandler;
use Notification\Application\Command\Send\SendDelivery;
use Notification\Application\Command\Send\SendDigest;
use Notification\Application\Command\Send\Unsubscribe;
use Notification\Application\EventHandler\QueueDelivery;
use Notification\Application\Notice\Recipients;
use Notification\Application\Port\NoticeView;
use Notification\Application\Query\Digest;
use Notification\Application\Query\Handler\ListDigestsHandler;
use Notification\Application\Query\Handler\ListDueDeliveriesHandler;
use Notification\Application\Query\ListDigests;
use Notification\Application\Query\ListDueDeliveries;
use Notification\Application\Webhook\WebhookRequest;
use Notification\Domain\Event\DeliveryScheduled;
use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\DeliveryChannel;
use Notification\Domain\Model\DeliveryStatus;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\Notice;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Model\RuleThresholds;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Model\WebhookChannel;
use Notification\Domain\Model\WebhookSecretBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\FilterRecipients;
use Shared\Application\Contract\Organization\FindTeamNames;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Notification\Support\BoundSecretBox;
use Tests\Notification\Support\InMemoryDeliveries;
use Tests\Notification\Support\InMemoryNotices;
use Tests\Notification\Support\InMemoryRules;
use Tests\Notification\Support\InMemorySubscriptions;
use Tests\Notification\Support\InMemoryWebhooks;
use Tests\Notification\Support\RecordingNotificationMailer;
use Tests\Notification\Support\ScriptedWebhookCaller;
use Tests\Organization\Support\FixedQueries;
use Tests\Organization\Support\RecordingCommands;

/**
 * The deliveries leave: an immediate one at once (email or signed webhook), the digests grouped per account; who can
 * no longer read a notice gets nothing; a failure is an attempt, never lost.
 */
final class SendTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string ZTEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';
    private const string PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f11';
    private const string ALICE = '019a6b2c-1f00-7a11-8b22-3c44d55e6f51';
    private const string BOB = '019a6b2c-1f00-7a11-8b22-3c44d55e6f52';
    private const string CAROL = '019a6b2c-1f00-7a11-8b22-3c44d55e6f53';

    private InMemoryRules $rules;
    private InMemoryWebhooks $webhooks;
    private InMemorySubscriptions $subscriptions;
    private InMemoryNotices $notices;
    private InMemoryDeliveries $deliveries;
    private RecordingNotificationMailer $mailer;
    private ScriptedWebhookCaller $caller;
    private MockClock $clock;
    private FixedQueries $queries;

    protected function setUp(): void
    {
        $this->rules = new InMemoryRules();
        $this->webhooks = new InMemoryWebhooks();
        $this->subscriptions = new InMemorySubscriptions();
        $this->notices = new InMemoryNotices();
        $this->deliveries = new InMemoryDeliveries();
        $this->mailer = new RecordingNotificationMailer();
        $this->caller = new ScriptedWebhookCaller();
        $this->clock = new MockClock('2026-11-12 10:00:00');
        $this->queries = new FixedQueries([
            // Bob does not see the project (nor the fleet); Carol's account is deactivated; others are unknown.
            FilterRecipients::class => static fn (object $q): array => $q instanceof FilterRecipients ? array_values(array_diff($q->userIds, [self::BOB])) : [],
            FindAccounts::class => static fn (object $q): array => $q instanceof FindAccounts ? array_intersect_key([
                self::ALICE => new AccountView(self::ALICE, 'alice@example.com', true),
                self::BOB => new AccountView(self::BOB, 'bob@example.com', true),
                self::CAROL => new AccountView(self::CAROL, 'carol@example.com', false),
            ], array_flip($q->userIds)) : [],
            FindTeamNames::class => static fn (object $q): array => $q instanceof FindTeamNames ? array_intersect_key([self::TEAM => 'Platform', self::ZTEAM => 'Apps'], array_flip($q->teamIds)) : [],
        ]);
    }

    #[Test]
    public function it_should_queue_an_immediate_delivery_at_once(): void
    {
        $notice = $this->notice($this->rule());
        $immediate = Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Email, self::ALICE, Frequency::Immediate, $this->clock->now());
        $daily = Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Email, self::ALICE, Frequency::Daily, $this->clock->now());

        self::assertEquals([new DeliveryScheduled($immediate->id()->toRfc4122())], $immediate->releaseEvents());
        self::assertSame([], $daily->releaseEvents());

        $commands = new RecordingCommands();
        new QueueDelivery($commands)(new DeliveryScheduled('d-1'));
        self::assertEquals([new SendDelivery('d-1')], $commands->dispatched);
    }

    #[Test]
    public function it_should_email_a_subscriber_who_still_reads_it(): void
    {
        $subscription = $this->subscribe(self::ALICE);
        $notice = $this->notice($this->rule());
        $delivery = $this->delivery($notice, DeliveryChannel::Email, self::ALICE);

        $this->send($delivery);

        self::assertEquals([['alice@example.com', new NoticeView($notice->id()->toRfc4122(), 'vulnerability_opened', self::TEAM, 'Platform', self::PROJECT, ['finding' => ['title' => 'RCE']], $notice->createdAt(), $subscription->id()->toRfc4122())]], $this->mailer->notices);
        self::assertSame(DeliveryStatus::Sent, $delivery->status());
        self::assertEquals($this->clock->now(), $delivery->doneAt());

        $this->send($delivery);
        self::assertCount(1, $this->mailer->notices, 'Sent: never again.');
    }

    #[Test]
    public function it_should_count_a_failed_email_as_an_attempt(): void
    {
        $this->subscribe(self::ALICE);
        $delivery = $this->delivery($this->notice($this->rule()), DeliveryChannel::Email, self::ALICE);
        $this->mailer->failure = 'Mailer (TransportException)';

        $this->send($delivery);
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertSame(1, $delivery->attempts());
        self::assertSame('Mailer (TransportException)', $delivery->lastError());

        $this->send($delivery);
        self::assertSame(1, $delivery->attempts(), 'Not due yet: the next attempt is in a minute.');

        $this->clock->sleep(60);
        $this->mailer->failure = null;
        $this->send($delivery);
        self::assertSame(DeliveryStatus::Sent, $delivery->status());
        self::assertSame(2, $delivery->attempts());
    }

    /**
     * @return iterable<string, array{string, ?Frequency}>
     */
    public static function unreadable(): iterable
    {
        yield 'unsubscribed' => [self::ALICE, Frequency::Off];
        yield 'never subscribed' => [self::ALICE, null];
        yield 'no longer sees the project' => [self::BOB, Frequency::Immediate];
        yield 'deactivated' => [self::CAROL, Frequency::Immediate];
        yield 'unknown account' => ['019a6b2c-1f00-7a11-8b22-3c44d55e6f59', Frequency::Immediate];
    }

    #[Test]
    #[DataProvider('unreadable')]
    public function it_should_drop_an_email_nobody_may_read(string $user, ?Frequency $frequency): void
    {
        if (null !== $frequency) {
            $this->subscribe($user, $frequency);
        }
        $delivery = $this->delivery($this->notice($this->rule()), DeliveryChannel::Email, $user);

        $this->send($delivery);

        self::assertSame([], $this->mailer->notices);
        self::assertSame(DeliveryStatus::Dropped, $delivery->status());
        self::assertSame(0, $delivery->attempts());
    }

    #[Test]
    public function it_should_drop_a_delivery_whose_notice_is_gone(): void
    {
        $delivery = $this->delivery($this->notice($this->rule()), DeliveryChannel::Email, self::ALICE);
        $this->notices->notices = [];

        $this->send($delivery);

        self::assertSame(DeliveryStatus::Dropped, $delivery->status());
    }

    #[Test]
    public function it_should_ignore_an_unknown_delivery(): void
    {
        foreach (['nope', Uuid::v7()->toRfc4122()] as $id) {
            new SendDeliveryHandler($this->deliveries, $this->notices, $this->rules, $this->webhooks, $this->recipients(), $this->mailer, $this->caller, new BoundSecretBox(), $this->clock)(new SendDelivery($id));
        }

        self::assertSame([], $this->queries->asked);
    }

    #[Test]
    public function it_should_call_a_webhook_with_a_signed_request(): void
    {
        $webhook = $this->webhook();
        $notice = $this->notice($this->rule([$webhook->id()]));
        $delivery = $this->delivery($notice, DeliveryChannel::Webhook, $webhook->id()->toRfc4122());

        $this->send($delivery);

        self::assertCount(1, $this->caller->requests);
        self::assertEquals(WebhookRequest::for('https://hooks.example.com/in', 'whsec_test', $notice, $delivery, $this->clock->now()), $this->caller->requests[0]);
        self::assertSame(DeliveryStatus::Sent, $delivery->status());
        self::assertTrue($webhook->lastSucceeded());
        self::assertEquals($this->clock->now(), $webhook->lastCalledAt());
        self::assertSame([], $this->queries->asked, 'A webhook is no account.');
    }

    #[Test]
    public function it_should_count_a_failed_webhook_as_an_attempt(): void
    {
        $webhook = $this->webhook();
        $delivery = $this->delivery($this->notice($this->rule([$webhook->id()])), DeliveryChannel::Webhook, $webhook->id()->toRfc4122());
        $this->caller->failure = 'HTTP 500';

        $this->send($delivery);

        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertSame('HTTP 500', $delivery->lastError());
        self::assertFalse($webhook->lastSucceeded());
        self::assertSame('HTTP 500', $webhook->lastError());
    }

    #[Test]
    public function it_should_drop_a_webhook_no_longer_called(): void
    {
        $webhook = $this->webhook();
        $foreign = WebhookChannel::register(Uuid::v7(), Uuid::fromString(self::ZTEAM), 'Foreign', 'https://foreign.example.com', new BoundSecretBox()->encrypt('x', 'x'), $this->clock->now());
        $this->webhooks->add($foreign);
        $rule = $this->rule([$webhook->id(), $foreign->id()]);
        $notice = $this->notice($rule);
        $deliveries = [
            'another team' => $this->delivery($notice, DeliveryChannel::Webhook, $foreign->id()->toRfc4122()),
            'unknown' => $this->delivery($notice, DeliveryChannel::Webhook, Uuid::v7()->toRfc4122()),
            'not an id' => $this->delivery($notice, DeliveryChannel::Webhook, 'nope'),
        ];
        $rule->forgetWebhook($webhook->id());
        $deliveries['no longer in the rule'] = $this->delivery($notice, DeliveryChannel::Webhook, $webhook->id()->toRfc4122());
        $other = $this->notice($this->rule([$webhook->id()]), 'other');
        $this->rules->rules = [$rule];
        $deliveries['rule removed'] = $this->delivery($other, DeliveryChannel::Webhook, $webhook->id()->toRfc4122());

        foreach ($deliveries as $reason => $delivery) {
            $this->send($delivery);
            self::assertSame(DeliveryStatus::Dropped, $delivery->status(), $reason);
        }

        self::assertSame([], $this->caller->requests);
        self::assertNull($webhook->lastCalledAt());
    }

    #[Test]
    public function it_should_sign_a_versioned_json(): void
    {
        self::assertSame('sha256=83171cbb758ad8691565f1c3f7f4593a985a0451a5e67181aba825c6a58bb106', WebhookRequest::signature('whsec_test', '1700000000', '{"version":1}'));

        $notice = $this->notice($this->rule(), content: ['finding' => ['title' => 'Faille é/1']]);
        $delivery = $this->delivery($notice, DeliveryChannel::Webhook, 'w');
        $request = WebhookRequest::for('https://hooks.example.com/in', 'whsec_test', $notice, $delivery, new \DateTimeImmutable('@1700000000'));
        $body = \sprintf('{"version":1,"id":"%s","delivery":"%s","event":"vulnerability_opened","occurred_at":"2026-11-12T10:00:00+00:00","team_id":"%s","project_id":"%s","data":{"finding":{"title":"Faille é/1"}}}', $notice->id()->toRfc4122(), $delivery->id()->toRfc4122(), self::TEAM, self::PROJECT);

        self::assertSame('https://hooks.example.com/in', $request->url);
        self::assertSame($body, $request->body);
        self::assertSame([
            'Content-Type' => 'application/json',
            'X-DepVerify-Event' => 'vulnerability_opened',
            'X-DepVerify-Delivery' => $delivery->id()->toRfc4122(),
            'X-DepVerify-Timestamp' => '1700000000',
            'X-DepVerify-Signature' => WebhookRequest::signature('whsec_test', '1700000000', $body),
        ], $request->headers);

        $fleet = Notice::of(Uuid::v7(), $this->rule(project: null), NotificationEvent::AgentPending, 'k', [], $this->clock->now());
        self::assertStringContainsString('"project_id":null,"data":[]', WebhookRequest::for('https://x.example.com', 's', $fleet, $delivery, $this->clock->now())->body);
    }

    #[Test]
    public function it_should_gather_a_digest_per_account(): void
    {
        $this->subscribe(self::ALICE, Frequency::Daily);
        $fleet = $this->subscribe(self::ALICE, Frequency::Daily, project: null);
        $apps = $this->subscribe(self::ALICE, Frequency::Daily, team: self::ZTEAM);
        $project = $this->subscriptions->subscriptions[0];
        $first = $this->notice($this->rule(), 'first');
        $this->clock->sleep(60);
        $second = $this->notice($this->rule(), 'second');
        $inFleet = $this->notice($this->rule(project: null), 'fleet');
        $inApps = $this->notice($this->rule(team: self::ZTEAM), 'apps');
        $ids = [];
        foreach ([$second, $inFleet, $first, $inApps] as $notice) {
            $ids[] = $this->delivery($notice, DeliveryChannel::Email, self::ALICE, Frequency::Daily)->id()->toRfc4122();
        }
        $this->clock->sleep(3600);

        $this->digest(self::ALICE, Frequency::Daily, $ids);

        self::assertCount(1, $this->mailer->digests);
        [$email, $frequency, $views] = $this->mailer->digests[0];
        self::assertSame('alice@example.com', $email);
        self::assertSame(Frequency::Daily, $frequency);
        self::assertSame(
            [['Apps', 'apps', $apps->id()->toRfc4122()], ['Platform', 'fleet', $fleet->id()->toRfc4122()], ['Platform', 'first', $project->id()->toRfc4122()], ['Platform', 'second', $project->id()->toRfc4122()]],
            array_map(static fn (NoticeView $v): array => [$v->teamName, $v->content['key'] ?? null, $v->subscriptionId], $views),
            'By team name, the fleet first, the oldest first.',
        );
        foreach ($this->deliveries->deliveries as $delivery) {
            self::assertSame(DeliveryStatus::Sent, $delivery->status());
            self::assertEquals($this->clock->now(), $delivery->doneAt());
        }
        self::assertCount(3, array_filter($this->queries->asked, static fn (object $q): bool => $q instanceof FilterRecipients), 'Once per scope, not per notice.');
    }

    #[Test]
    public function it_should_keep_a_digest_to_its_own_pending_emails(): void
    {
        $this->subscribe(self::ALICE, Frequency::Weekly);
        $notice = $this->notice($this->rule());
        $kept = $this->delivery($notice, DeliveryChannel::Email, self::ALICE, Frequency::Weekly);
        $others = [
            $this->delivery($this->notice($this->rule(), 'b'), DeliveryChannel::Email, self::CAROL, Frequency::Weekly),
            $this->delivery($this->notice($this->rule(), 'c'), DeliveryChannel::Email, self::ALICE, Frequency::Daily),
            $this->delivery($this->notice($this->rule(), 'd'), DeliveryChannel::Webhook, self::ALICE, Frequency::Immediate),
            $sent = $this->delivery($this->notice($this->rule(), 'e'), DeliveryChannel::Email, self::ALICE, Frequency::Weekly),
        ];
        $sent->sent($this->clock->now());

        $this->digest(self::ALICE, Frequency::Weekly, [...array_map(static fn (Delivery $d): string => $d->id()->toRfc4122(), [$kept, ...$others]), 'nope', Uuid::v7()->toRfc4122()]);

        self::assertCount(1, $this->mailer->digests[0][2]);
        self::assertSame(DeliveryStatus::Sent, $kept->status());
        self::assertSame([DeliveryStatus::Pending, DeliveryStatus::Pending, DeliveryStatus::Pending, DeliveryStatus::Sent], array_map(static fn (Delivery $d): DeliveryStatus => $d->status(), $others));
        self::assertSame(1, $sent->attempts());
    }

    #[Test]
    public function it_should_drop_from_a_digest_what_the_account_may_no_longer_read(): void
    {
        $this->subscribe(self::BOB, Frequency::Daily);
        $this->subscribe(self::ALICE, Frequency::Off);
        $bob = $this->delivery($this->notice($this->rule()), DeliveryChannel::Email, self::BOB, Frequency::Daily);
        $alice = $this->delivery($this->notice($this->rule(), 'a'), DeliveryChannel::Email, self::ALICE, Frequency::Daily);
        $gone = $this->delivery($this->notice($this->rule(), 'gone'), DeliveryChannel::Email, self::ALICE, Frequency::Daily);
        array_pop($this->notices->notices);

        $this->digest(self::BOB, Frequency::Daily, [$bob->id()->toRfc4122()]);
        $this->digest(self::ALICE, Frequency::Daily, [$alice->id()->toRfc4122(), $gone->id()->toRfc4122()]);

        self::assertSame([], $this->mailer->digests);
        self::assertSame(DeliveryStatus::Dropped, $bob->status());
        self::assertSame(DeliveryStatus::Dropped, $alice->status());
        self::assertSame(DeliveryStatus::Dropped, $gone->status());
    }

    #[Test]
    public function it_should_drop_the_digest_of_a_deactivated_account(): void
    {
        $this->subscribe(self::CAROL, Frequency::Daily);
        $delivery = $this->delivery($this->notice($this->rule()), DeliveryChannel::Email, self::CAROL, Frequency::Daily);

        $this->digest(self::CAROL, Frequency::Daily, [$delivery->id()->toRfc4122()]);

        self::assertSame(DeliveryStatus::Dropped, $delivery->status());
        self::assertSame([FindAccounts::class], array_map(static fn (object $q): string => $q::class, $this->queries->asked), 'Nothing more is asked.');
    }

    #[Test]
    public function it_should_try_a_failed_digest_again_with_the_next_one(): void
    {
        $this->subscribe(self::ALICE, Frequency::Daily);
        $delivery = $this->delivery($this->notice($this->rule()), DeliveryChannel::Email, self::ALICE, Frequency::Daily);
        $this->mailer->failure = 'Mailer (TransportException)';

        $this->digest(self::ALICE, Frequency::Daily, [$delivery->id()->toRfc4122()]);

        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertSame(1, $delivery->attempts());
        self::assertSame('Mailer (TransportException)', $delivery->lastError());
    }

    #[Test]
    public function it_should_send_nothing_without_a_pending_delivery(): void
    {
        $this->digest(self::ALICE, Frequency::Daily, []);

        self::assertSame([], $this->mailer->digests);
        self::assertSame([], $this->queries->asked);
    }

    #[Test]
    public function it_should_list_the_digests_of_the_day_and_on_monday_of_the_week(): void
    {
        $notice = $this->notice($this->rule());
        $a1 = $this->delivery($notice, DeliveryChannel::Email, self::ALICE, Frequency::Daily);
        $b1 = $this->delivery($notice, DeliveryChannel::Email, self::BOB, Frequency::Daily);
        $a2 = $this->delivery($this->notice($this->rule(), 'x'), DeliveryChannel::Email, self::ALICE, Frequency::Daily);
        $w = $this->delivery($notice, DeliveryChannel::Email, self::CAROL, Frequency::Weekly);
        $this->delivery($notice, DeliveryChannel::Webhook, 'hook', Frequency::Immediate);

        // 2026-11-12 is a Thursday.
        self::assertEquals([
            new Digest(self::ALICE, 'daily', [$a1->id()->toRfc4122(), $a2->id()->toRfc4122()]),
            new Digest(self::BOB, 'daily', [$b1->id()->toRfc4122()]),
        ], new ListDigestsHandler($this->deliveries, $this->clock)(new ListDigests()));

        $this->clock->modify('2026-11-16 07:00');
        $monday = new ListDigestsHandler($this->deliveries, $this->clock)(new ListDigests(limit: 2));
        self::assertEquals([
            new Digest(self::ALICE, 'daily', [$a1->id()->toRfc4122()]),
            new Digest(self::BOB, 'daily', [$b1->id()->toRfc4122()]),
            new Digest(self::CAROL, 'weekly', [$w->id()->toRfc4122()]),
        ], $monday);
    }

    #[Test]
    public function it_should_list_the_deliveries_due_again(): void
    {
        $notice = $this->notice($this->rule());
        $due = $this->delivery($notice, DeliveryChannel::Email, self::ALICE);
        $later = $this->delivery($notice, DeliveryChannel::Email, self::BOB);
        $later->attemptFailed('x', $this->clock->now());
        $this->delivery($notice, DeliveryChannel::Email, self::CAROL);

        self::assertSame([$due->id()->toRfc4122()], new ListDueDeliveriesHandler($this->deliveries, $this->clock)(new ListDueDeliveries(1)));
        self::assertSame(500, new ListDueDeliveries()->limit);
        self::assertSame(5000, new ListDigests()->limit);
    }

    #[Test]
    public function it_should_unsubscribe_from_a_link(): void
    {
        $subscription = $this->subscribe(self::ALICE);
        $handler = new UnsubscribeHandler($this->subscriptions);

        $handler(new Unsubscribe($subscription->id()->toRfc4122()));
        self::assertSame(Frequency::Off, $subscription->frequency());

        $command = new Unsubscribe($subscription->id()->toRfc4122());
        self::assertSame('notification.unsubscribe', $command->auditAction()->name);
        self::assertSame('notification_subscription', $command->auditTarget()->type);
        self::assertSame($subscription->id()->toRfc4122(), $command->auditTarget()->id());

        foreach (['nope', Uuid::v7()->toRfc4122()] as $id) {
            try {
                $handler(new Unsubscribe($id));
                self::fail('Expected a 404.');
            } catch (ResourceNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function send(Delivery $delivery): void
    {
        new SendDeliveryHandler($this->deliveries, $this->notices, $this->rules, $this->webhooks, $this->recipients(), $this->mailer, $this->caller, new BoundSecretBox(), $this->clock)(new SendDelivery($delivery->id()->toRfc4122()));
    }

    /**
     * @param list<string> $ids
     */
    private function digest(string $user, Frequency $frequency, array $ids): void
    {
        new SendDigestHandler($this->deliveries, $this->notices, $this->recipients(), $this->mailer, $this->clock)(new SendDigest($user, $frequency->value, $ids));
    }

    private function recipients(): Recipients
    {
        return new Recipients($this->subscriptions, $this->queries);
    }

    /**
     * @param list<Uuid> $webhooks
     */
    private function rule(array $webhooks = [], ?string $project = self::PROJECT, string $team = self::TEAM): NotificationRule
    {
        $rule = NotificationRule::define(Uuid::v7(), Uuid::fromString($team), null === $project ? null : Uuid::fromString($project), null, 'Rule', [NotificationEvent::VulnerabilityOpened, NotificationEvent::AgentPending], new RuleThresholds(), $webhooks, true, $this->clock->now());
        $this->rules->add($rule);

        return $rule;
    }

    /**
     * @param array<string, mixed>|null $content
     */
    private function notice(NotificationRule $rule, string $key = 'k', ?array $content = null): Notice
    {
        $notice = Notice::of(Uuid::v7(), $rule, NotificationEvent::VulnerabilityOpened, $key, $content ?? ('k' === $key ? ['finding' => ['title' => 'RCE']] : ['key' => $key]), $this->clock->now());
        $this->notices->add($notice);

        return $notice;
    }

    private function delivery(Notice $notice, DeliveryChannel $channel, string $target, Frequency $frequency = Frequency::Immediate): Delivery
    {
        $delivery = Delivery::schedule(Uuid::v7(), $notice, $channel, $target, $frequency, $this->clock->now());
        $this->deliveries->add($delivery);

        return $delivery;
    }

    private function webhook(): WebhookChannel
    {
        $id = Uuid::v7();
        $webhook = WebhookChannel::register($id, Uuid::fromString(self::TEAM), 'Hook', 'https://hooks.example.com/in', new BoundSecretBox()->encrypt('whsec_test', WebhookSecretBinding::for($id)), $this->clock->now());
        $this->webhooks->add($webhook);

        return $webhook;
    }

    private function subscribe(string $user, Frequency $frequency = Frequency::Immediate, ?string $project = self::PROJECT, string $team = self::TEAM): Subscription
    {
        $subscription = Subscription::subscribe(Uuid::v7(), Uuid::fromString($team), null === $project ? null : Uuid::fromString($project), Uuid::fromString($user), $frequency, $this->clock->now());
        $this->subscriptions->add($subscription);

        return $subscription;
    }
}
