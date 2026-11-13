<?php

declare(strict_types=1);

namespace Tests\Notification\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\DeliveryChannel;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\Notice;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Model\RuleThresholds;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Model\WebhookChannel;
use Notification\Infrastructure\Doctrine\DoctrineDeliveryRepository;
use Notification\Infrastructure\Doctrine\DoctrineNoticeRepository;
use Notification\Infrastructure\Doctrine\DoctrineNotificationRuleRepository;
use Notification\Infrastructure\Doctrine\DoctrineSubscriptionRepository;
use Notification\Infrastructure\Doctrine\DoctrineWebhookChannelRepository;
use Notification\Infrastructure\Doctrine\NotificationManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The Notification tables against PostgreSQL: rules found by their webhooks and events (JSON), an occurrence told
 * once per rule and recipient, the deliveries due, everything erased with the team.
 */
final class RepositoriesTest extends KernelTestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string OTHER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';
    private const string PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f11';
    private const string ENV = '019a6b2c-1f00-7a11-8b22-3c44d55e6f21';

    private NotificationManager $manager;
    private DoctrineNotificationRuleRepository $rules;
    private DoctrineWebhookChannelRepository $webhooks;
    private DoctrineSubscriptionRepository $subscriptions;
    private DoctrineNoticeRepository $notices;
    private DoctrineDeliveryRepository $deliveries;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = new NotificationManager(self::getContainer()->get('doctrine'));
        $this->rules = new DoctrineNotificationRuleRepository($this->manager);
        $this->webhooks = new DoctrineWebhookChannelRepository($this->manager);
        $this->subscriptions = new DoctrineSubscriptionRepository($this->manager);
        $this->notices = new DoctrineNoticeRepository($this->manager);
        $this->deliveries = new DoctrineDeliveryRepository($this->manager);
        $this->now = new \DateTimeImmutable('2026-11-12 08:00:00');
    }

    #[Test]
    public function it_should_find_the_rules_by_scope_webhook_and_event(): void
    {
        $hook = WebhookChannel::register(Uuid::v7(), Uuid::fromString(self::TEAM), 'Zeta', 'https://hooks.example.com', 'sealed', $this->now);
        $alpha = WebhookChannel::register(Uuid::v7(), Uuid::fromString(self::TEAM), 'Alpha', 'https://alpha.example.com', 'sealed', $this->now);
        $this->webhooks->add($hook);
        $this->webhooks->add($alpha);
        $project = $this->rule([NotificationEvent::VulnerabilityOpened], self::PROJECT, self::ENV, [$hook->id()]);
        $fleet = $this->rule([NotificationEvent::AgentSilent, NotificationEvent::AgentPending], null, null, [$hook->id(), $alpha->id()], new RuleThresholds(silentHours: 12));
        $this->rule([NotificationEvent::AgentPending], null);
        $this->flush();

        self::assertSame(['Alpha', 'Zeta'], array_map(static fn (WebhookChannel $w): string => $w->name(), $this->webhooks->ofTeam(Uuid::fromString(self::TEAM))));
        self::assertSame('sealed', $this->webhooks->find($hook->id())?->secret());
        self::assertSame([$project->id()->toRfc4122()], $this->ids($this->rules->ofScope(Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT))));
        self::assertCount(2, $this->rules->ofScope(Uuid::fromString(self::TEAM), null));
        self::assertSame([$project->id()->toRfc4122(), $fleet->id()->toRfc4122()], $this->ids($this->rules->callingWebhook($hook->id())));
        self::assertSame([$fleet->id()->toRfc4122()], $this->ids($this->rules->callingWebhook($alpha->id())));
        self::assertSame([$fleet->id()->toRfc4122()], $this->ids($this->rules->tellingSilentAgents()));
        self::assertEquals(new RuleThresholds(silentHours: 12), $this->rules->find($fleet->id())?->thresholds());
        self::assertTrue($this->rules->hasProject(Uuid::fromString(self::PROJECT)));
        self::assertTrue($this->rules->hasEnvironment(Uuid::fromString(self::ENV)));
        self::assertFalse($this->rules->hasProject(Uuid::v7()));
        self::assertFalse($this->rules->hasEnvironment(Uuid::v7()));

        $rule = $this->rules->find($project->id());
        self::assertNotNull($rule);
        $this->rules->remove($rule);
        $webhook = $this->webhooks->find($alpha->id());
        self::assertNotNull($webhook);
        $this->webhooks->remove($webhook);
        $this->flush();
        self::assertNull($this->rules->find($project->id()));
        self::assertCount(1, $this->webhooks->ofTeam(Uuid::fromString(self::TEAM)));
    }

    #[Test]
    public function it_should_keep_one_subscription_per_account_and_scope(): void
    {
        $user = Uuid::v7();
        $team = Uuid::fromString(self::TEAM);
        $this->subscriptions->add(Subscription::subscribe(Uuid::v7(), $team, Uuid::fromString(self::PROJECT), $user, Frequency::Daily, $this->now));
        $this->subscriptions->add($fleet = Subscription::subscribe(Uuid::v7(), $team, null, $user, Frequency::Off, $this->now));
        $this->flush();

        self::assertSame(Frequency::Daily, $this->subscriptions->of($user, $team, Uuid::fromString(self::PROJECT))?->frequency());
        self::assertSame(Frequency::Off, $this->subscriptions->of($user, $team, null)?->frequency());
        self::assertNull($this->subscriptions->of(Uuid::v7(), $team, null));
        self::assertCount(1, $this->subscriptions->ofScope($team, null));

        $found = $this->subscriptions->find($fleet->id());
        self::assertSame(Frequency::Off, $found?->frequency());
        $this->subscriptions->remove($found);
        $this->flush();
        self::assertSame([], $this->subscriptions->ofScope($team, null));

        $this->subscriptions->add(Subscription::subscribe(Uuid::v7(), $team, Uuid::fromString(self::PROJECT), $user, Frequency::Weekly, $this->now));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->flush();
    }

    #[Test]
    public function it_should_tell_an_occurrence_once_and_list_the_due_deliveries(): void
    {
        $rule = $this->rule([NotificationEvent::VulnerabilityOpened], self::PROJECT);
        $notice = Notice::of(Uuid::v7(), $rule, NotificationEvent::VulnerabilityOpened, 'finding:1', ['finding' => ['title' => 'RCE', 'cvss' => 9.8]], $this->now);
        $this->notices->add($notice);
        $this->deliveries->add($immediate = Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Webhook, 'hook', Frequency::Immediate, $this->now));
        $this->deliveries->add($later = Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Email, 'user-1', Frequency::Immediate, $this->now));
        $this->deliveries->add($daily = Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Email, 'user-2', Frequency::Daily, $this->now));
        $this->deliveries->add(Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Email, 'user-3', Frequency::Weekly, $this->now));
        $later->attemptFailed('SMTP down', $this->now);
        $this->flush();

        self::assertTrue($this->notices->has($rule->id(), 'finding:1'));
        self::assertFalse($this->notices->has($rule->id(), 'finding:2'));
        self::assertSame(['finding' => ['title' => 'RCE', 'cvss' => 9.8]], $this->notices->find($notice->id())?->content());
        self::assertSame([$notice->id()->toRfc4122()], array_keys($this->notices->findMany([$notice->id(), Uuid::v7()])));
        self::assertSame([], $this->notices->findMany([]));
        self::assertSame([$immediate->id()->toRfc4122()], $this->ids($this->deliveries->due($this->now, 10)));
        self::assertSame([$immediate->id()->toRfc4122(), $later->id()->toRfc4122()], $this->ids($this->deliveries->due($this->now->modify('+1 minute'), 10)));
        self::assertCount(1, $this->deliveries->due($this->now->modify('+1 minute'), 1));
        self::assertSame([$daily->id()->toRfc4122()], $this->ids($this->deliveries->pendingDigest(Frequency::Daily, 10)));
        self::assertSame(1, $this->deliveries->find($later->id())?->attempts());

        $this->deliveries->add(Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Webhook, 'hook', Frequency::Immediate, $this->now));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->flush();
    }

    #[Test]
    public function it_should_refuse_a_second_notice_of_an_occurrence(): void
    {
        $rule = $this->rule([NotificationEvent::Outdated], self::PROJECT);
        $this->notices->add(Notice::of(Uuid::v7(), $rule, NotificationEvent::Outdated, 'finding:1', [], $this->now));
        $this->notices->add(Notice::of(Uuid::v7(), $rule, NotificationEvent::Outdated, 'finding:1', [], $this->now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->flush();
    }

    #[Test]
    public function it_should_erase_a_team(): void
    {
        foreach ([self::TEAM, self::OTHER] as $team) {
            $rule = $this->rule([NotificationEvent::Outdated], null, team: $team);
            $notice = Notice::of(Uuid::v7(), $rule, NotificationEvent::Outdated, 'k', [], $this->now);
            $this->notices->add($notice);
            $this->deliveries->add(Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Email, 'u', Frequency::Daily, $this->now));
            $this->webhooks->add(WebhookChannel::register(Uuid::v7(), Uuid::fromString($team), 'Hook', 'https://hooks.example.com', 's', $this->now));
            $this->subscriptions->add(Subscription::subscribe(Uuid::v7(), Uuid::fromString($team), null, Uuid::v7(), Frequency::Daily, $this->now));
        }
        $this->flush();

        $team = Uuid::fromString(self::TEAM);
        $this->deliveries->deleteTeam($team);
        $this->notices->deleteTeam($team);
        $this->subscriptions->deleteTeam($team);
        $this->rules->deleteTeam($team);
        $this->webhooks->deleteTeam($team);
        $this->flush();

        $connection = $this->manager->get()->getConnection();
        foreach (['notification_rule', 'notification_webhook', 'notification_subscription', 'notification_notice', 'notification_delivery'] as $table) {
            self::assertSame([self::OTHER], $connection->fetchFirstColumn(\sprintf('SELECT DISTINCT team_id FROM %s', $table)), $table);
        }
    }

    /**
     * @param list<NotificationEvent> $events
     * @param list<Uuid>              $webhooks
     */
    private function rule(array $events, ?string $project, ?string $environment = null, array $webhooks = [], ?RuleThresholds $thresholds = null, string $team = self::TEAM): NotificationRule
    {
        $rule = NotificationRule::define(Uuid::v7(), Uuid::fromString($team), null === $project ? null : Uuid::fromString($project), null === $environment ? null : Uuid::fromString($environment), 'Rule', $events, $thresholds ?? new RuleThresholds(), $webhooks, true, $this->now);
        $this->rules->add($rule);
        $this->now = $this->now->modify('+1 second');

        return $rule;
    }

    /**
     * @param list<NotificationRule|Delivery> $entities
     *
     * @return list<string>
     */
    private function ids(array $entities): array
    {
        return array_map(static fn (NotificationRule|Delivery $e): string => $e->id()->toRfc4122(), $entities);
    }

    private function flush(): void
    {
        $this->manager->get()->flush();
        $this->manager->get()->clear();
    }
}
