<?php

declare(strict_types=1);

namespace Tests\Notification\Unit;

use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\DeliveryChannel;
use Notification\Domain\Model\DeliveryStatus;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\InvalidNotificationSetting;
use Notification\Domain\Model\Notice;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Model\RuleThresholds;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Model\WebhookChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * The webhooks, the subscriptions, and the outbox: a notice per rule and occurrence, a delivery per recipient,
 * attempted again later then failed for good.
 */
final class OutboxTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f11';

    #[Test]
    public function it_should_register_a_webhook(): void
    {
        $now = new \DateTimeImmutable('2026-11-12 10:00');
        $webhook = WebhookChannel::register(Uuid::v7(), Uuid::fromString(self::TEAM), ' Alertes ', ' HTTPS://hooks.example.com/depverify?x=1 ', 'sealed', $now);

        self::assertSame(self::TEAM, $webhook->teamId()->toRfc4122());
        self::assertSame('Alertes', $webhook->name());
        self::assertSame('HTTPS://hooks.example.com/depverify?x=1', $webhook->url());
        self::assertSame('sealed', $webhook->secret());
        self::assertSame($now, $webhook->createdAt());
        self::assertNull($webhook->lastCalledAt());
        self::assertNull($webhook->lastSucceeded());
        self::assertNull($webhook->lastError());

        $webhook->rotateSecret('resealed');
        self::assertSame('resealed', $webhook->secret());

        $webhook->called($now, u('x')->repeat(300)->toString());
        self::assertSame($now, $webhook->lastCalledAt());
        self::assertFalse($webhook->lastSucceeded());
        self::assertSame(255, u((string) $webhook->lastError())->length());

        $later = $now->modify('+1 hour');
        $webhook->called($later, null);
        self::assertSame($later, $webhook->lastCalledAt());
        self::assertTrue($webhook->lastSucceeded());
        self::assertNull($webhook->lastError());

        $webhook->edit('Autre', 'https://other.example.com');
        self::assertSame('Autre', $webhook->name());
        self::assertSame('https://other.example.com', $webhook->url());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidWebhooks(): iterable
    {
        $url = 'An HTTPS URL, without user nor password.';
        yield 'no name' => [' ', 'https://example.com', 'A name of 1 to 100 characters.'];
        yield 'long name' => [u('a')->repeat(101)->toString(), 'https://example.com', 'A name of 1 to 100 characters.'];
        yield 'http' => ['Hook', 'http://example.com', $url];
        yield 'no scheme' => ['Hook', 'example.com/hook', $url];
        yield 'no host' => ['Hook', 'https:///hook', $url];
        yield 'user' => ['Hook', 'https://me@example.com', $url];
        yield 'password' => ['Hook', 'https://:secret@example.com', $url];
        yield 'unreadable' => ['Hook', 'https://example.com:99999', $url];
        yield 'too long' => ['Hook', 'https://example.com/'.u('a')->repeat(2029)->toString(), $url];
    }

    #[Test]
    #[DataProvider('invalidWebhooks')]
    public function it_should_refuse_an_invalid_webhook(string $name, string $url, string $message): void
    {
        $this->expectExceptionObject(new InvalidNotificationSetting($message));
        WebhookChannel::register(Uuid::v7(), Uuid::fromString(self::TEAM), $name, $url, 'sealed', new \DateTimeImmutable());
    }

    #[Test]
    public function it_should_accept_the_longest_webhook(): void
    {
        $url = 'https://example.com/'.u('a')->repeat(2028)->toString();
        $webhook = WebhookChannel::register(Uuid::v7(), Uuid::fromString(self::TEAM), u('a')->repeat(100)->toString(), $url, 'sealed', new \DateTimeImmutable());

        self::assertSame($url, $webhook->url());
        self::assertSame(100, u($webhook->name())->length());
    }

    #[Test]
    public function it_should_subscribe_to_a_project_or_to_the_fleet(): void
    {
        $now = new \DateTimeImmutable('2026-11-12');
        $user = Uuid::v7();
        $project = Subscription::subscribe(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), $user, Frequency::Daily, $now);
        self::assertSame(self::TEAM, $project->teamId()->toRfc4122());
        self::assertSame(self::PROJECT, $project->projectId()?->toRfc4122());
        self::assertSame($user, $project->userId());
        self::assertSame(Frequency::Daily, $project->frequency());
        self::assertSame($now, $project->createdAt());
        self::assertSame(self::PROJECT, Subscription::scopeKey(Uuid::fromString(self::PROJECT)));
        self::assertSame('fleet', Subscription::scopeKey(null));

        $project->change(Frequency::Off);
        self::assertSame(Frequency::Off, $project->frequency());
        self::assertNull(Subscription::subscribe(Uuid::v7(), Uuid::fromString(self::TEAM), null, $user, Frequency::Immediate, $now)->projectId());
    }

    #[Test]
    public function it_should_keep_what_a_notice_tells(): void
    {
        $rule = $this->rule();
        $now = new \DateTimeImmutable('2026-11-12');
        $notice = Notice::of($id = Uuid::v7(), $rule, NotificationEvent::Outdated, 'finding:1', ['title' => 'x'], $now);

        self::assertSame($id, $notice->id());
        self::assertSame($rule->id(), $notice->ruleId());
        self::assertSame(self::TEAM, $notice->teamId()->toRfc4122());
        self::assertSame(self::PROJECT, $notice->projectId()?->toRfc4122());
        self::assertSame(NotificationEvent::Outdated, $notice->event());
        self::assertSame('finding:1', $notice->dedupeKey());
        self::assertSame(['title' => 'x'], $notice->content());
        self::assertSame($now, $notice->createdAt());
    }

    #[Test]
    public function it_should_attempt_a_delivery_again_then_fail(): void
    {
        $now = new \DateTimeImmutable('2026-11-12 10:00');
        $notice = Notice::of(Uuid::v7(), $this->rule(), NotificationEvent::Outdated, 'k', [], $now);
        $delivery = Delivery::schedule($id = Uuid::v7(), $notice, DeliveryChannel::Webhook, 'hook', Frequency::Immediate, $now);

        self::assertSame($id, $delivery->id());
        self::assertSame($notice->id(), $delivery->noticeId());
        self::assertSame(self::TEAM, $delivery->teamId()->toRfc4122());
        self::assertSame(DeliveryChannel::Webhook, $delivery->channel());
        self::assertSame('hook', $delivery->target());
        self::assertSame(Frequency::Immediate, $delivery->frequency());
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertSame(0, $delivery->attempts());
        self::assertSame($now, $delivery->nextAttemptAt());
        self::assertTrue($delivery->isDue($now));
        self::assertFalse($delivery->isDue($now->modify('-1 second')));

        $at = $now;
        foreach ([1, 5, 25, 125] as $attempt => $minutes) {
            $delivery->attemptFailed('HTTP 500', $at);
            self::assertSame($attempt + 1, $delivery->attempts());
            self::assertSame(DeliveryStatus::Pending, $delivery->status());
            self::assertEquals($at->modify(\sprintf('+%d minutes', $minutes)), $delivery->nextAttemptAt());
            self::assertFalse($delivery->isDue($at));
            self::assertSame('HTTP 500', $delivery->lastError());
            self::assertNull($delivery->doneAt());
            $at = $delivery->nextAttemptAt();
        }

        $delivery->attemptFailed(u('e')->repeat(300)->toString(), $at);
        self::assertSame($at, $delivery->nextAttemptAt(), 'No attempt after the last one.');
        self::assertSame(5, $delivery->attempts());
        self::assertSame(DeliveryStatus::Failed, $delivery->status());
        self::assertSame($at, $delivery->doneAt());
        self::assertSame(255, u((string) $delivery->lastError())->length());
        self::assertFalse($delivery->isDue($at->modify('+1 day')));
    }

    #[Test]
    public function it_should_name_its_rule(): void
    {
        $refusal = new InvalidNotificationSetting('Why.');
        self::assertSame('invalid_notification_setting', $refusal->ruleKey());
        self::assertSame('Why.', $refusal->getMessage());
    }

    #[Test]
    public function it_should_send_or_drop_a_delivery(): void
    {
        $now = new \DateTimeImmutable('2026-11-12 10:00');
        $notice = Notice::of(Uuid::v7(), $this->rule(), NotificationEvent::Outdated, 'k', [], $now);

        $sent = Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Email, 'user', Frequency::Weekly, $now);
        $sent->attemptFailed('SMTP down', $now);
        $sent->sent($later = $now->modify('+1 hour'));
        self::assertSame(DeliveryStatus::Sent, $sent->status());
        self::assertSame(2, $sent->attempts());
        self::assertSame($later, $sent->doneAt());
        self::assertNull($sent->lastError());

        $dropped = Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Email, 'user', Frequency::Daily, $now);
        $dropped->drop($later);
        self::assertSame(DeliveryStatus::Dropped, $dropped->status());
        self::assertSame($later, $dropped->doneAt());
        self::assertSame(0, $dropped->attempts());
    }

    /**
     * @return iterable<string, array{DeliveryChannel, Frequency}>
     */
    public static function invalidDeliveries(): iterable
    {
        yield 'email off' => [DeliveryChannel::Email, Frequency::Off];
        yield 'webhook off' => [DeliveryChannel::Webhook, Frequency::Off];
        yield 'webhook digest' => [DeliveryChannel::Webhook, Frequency::Daily];
    }

    #[Test]
    #[DataProvider('invalidDeliveries')]
    public function it_should_refuse_a_delivery_never_sent(DeliveryChannel $channel, Frequency $frequency): void
    {
        $now = new \DateTimeImmutable('2026-11-12 10:00');
        $notice = Notice::of(Uuid::v7(), $this->rule(), NotificationEvent::Outdated, 'k', [], $now);

        $this->expectExceptionObject(new InvalidNotificationSetting('A delivery is immediate (always for a webhook) or in a digest.'));
        Delivery::schedule(Uuid::v7(), $notice, $channel, 'x', $frequency, $now);
    }

    private function rule(): NotificationRule
    {
        return NotificationRule::define(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), null, 'Rule', [NotificationEvent::Outdated], new RuleThresholds(), [], true, new \DateTimeImmutable('2026-11-12'));
    }
}
