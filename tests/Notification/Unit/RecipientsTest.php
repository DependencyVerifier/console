<?php

declare(strict_types=1);

namespace Tests\Notification\Unit;

use Notification\Application\Command\Recipient\AttachRecipient;
use Notification\Application\Command\Recipient\ConfirmRecipient;
use Notification\Application\Command\Recipient\DetachRecipient;
use Notification\Application\Command\Recipient\Handler\AttachRecipientHandler;
use Notification\Application\Command\Recipient\Handler\ConfirmRecipientHandler;
use Notification\Application\Command\Recipient\Handler\DetachRecipientHandler;
use Notification\Application\EventHandler\SendRecipientConfirmation;
use Notification\Application\Notice\NoticeWriter;
use Notification\Application\Notice\Occurrence;
use Notification\Application\Notice\Recipients;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Event\RecipientAttached;
use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\InvalidNotificationSetting;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Model\NotificationSubject;
use Notification\Domain\Model\RecipientLinkInvalid;
use Notification\Domain\Model\RecipientQuotaReached;
use Notification\Domain\Model\RuleThresholds;
use Notification\Domain\Model\Subscription;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\FilterRecipients;
use Shared\Application\Contract\Organization\FindTeamNames;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\VisibleProjects;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\GrantedPermissions;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedTokens;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Identity\Support\RecordingLock;
use Tests\Notification\Support\FixedRecipientAccounts;
use Tests\Notification\Support\InMemoryDeliveries;
use Tests\Notification\Support\InMemoryNotices;
use Tests\Notification\Support\InMemoryRules;
use Tests\Notification\Support\InMemorySubscriptions;
use Tests\Notification\Support\InMemoryWebhooks;
use Tests\Notification\Support\RecordingAdmission;
use Tests\Notification\Support\RecordingConfirmations;
use Tests\Organization\Support\FixedQueries;

/**
 * The notification-only recipients: attached by a manager (Identity's account, Organization's membership, the quota),
 * silent until they confirm their address, detached by a manager.
 */
final class RecipientsTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f11';
    private const string OTHER_PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f12';
    private const string MANAGER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f50';
    private const string OPS = '019a6b2c-1f00-7a11-8b22-3c44d55e6f51';
    private const string SECOND = '019a6b2c-1f00-7a11-8b22-3c44d55e6f52';

    private InMemorySubscriptions $subscriptions;
    private FixedRecipientAccounts $accounts;
    private RecordingAdmission $admission;
    private RecordingLock $lock;
    private GrantedPermissions $permissions;
    private MockClock $clock;
    private ?int $quota = 50;
    /** @var list<string> */
    private array $visible = [self::PROJECT, self::OTHER_PROJECT];
    private FixedQueries $queries;

    protected function setUp(): void
    {
        $this->subscriptions = new InMemorySubscriptions();
        $this->accounts = new FixedRecipientAccounts(['ops@example.com' => self::OPS, 'second@example.com' => self::SECOND]);
        $this->admission = new RecordingAdmission();
        $this->lock = new RecordingLock();
        $this->permissions = new GrantedPermissions();
        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsManage];
        $this->clock = new MockClock('2026-11-13 09:00:00');
        $this->queries = new FixedQueries([
            GetVisibleProjects::class => fn (object $q): VisibleProjects => new VisibleProjects(false, $this->visible),
            GetTeamQuota::class => fn (object $q): ?int => $q instanceof GetTeamQuota && 'max_notification_only_accounts' === $q->quota && self::TEAM === $q->teamId ? $this->quota : throw new \LogicException('Unexpected quota.'),
            FindAccounts::class => static fn (object $q): array => $q instanceof FindAccounts ? array_intersect_key([self::OPS => new AccountView(self::OPS, 'ops@example.com', true), self::SECOND => new AccountView(self::SECOND, 'second@example.com', false)], array_flip($q->userIds)) : [],
            FindTeamNames::class => static fn (object $q): array => $q instanceof FindTeamNames ? array_intersect_key([self::TEAM => 'Platform'], array_flip($q->teamIds)) : [],
            FilterRecipients::class => static fn (object $q): array => $q instanceof FilterRecipients ? $q->userIds : [],
        ]);
    }

    #[Test]
    public function it_should_attach_a_silent_recipient_until_it_confirms(): void
    {
        $this->attach('ops@example.com', 'daily');

        self::assertSame(['notification.recipients:'.self::TEAM], $this->lock->keys);
        self::assertSame(['ops@example.com'], $this->accounts->asked);
        self::assertSame([[self::TEAM, self::PROJECT, self::OPS]], $this->admission->admitted);
        $subscription = $this->subscriptions->subscriptions[0];
        self::assertSame([self::TEAM, self::PROJECT, self::OPS, Frequency::Daily, self::MANAGER], [$subscription->teamId()->toRfc4122(), $subscription->projectId()?->toRfc4122(), $subscription->userId()->toRfc4122(), $subscription->frequency(), $subscription->addedBy()?->toRfc4122()]);
        self::assertFalse($subscription->isConfirmed());
        self::assertNull($subscription->confirmedAt());
        self::assertSame('h:url-token', $subscription->confirmationHash());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 09:00:00'), $subscription->confirmationExpiresAt());
        self::assertEquals([new RecipientAttached($subscription->id()->toRfc4122(), self::TEAM, self::OPS, 'url-token')], $subscription->releaseEvents());

        $this->clock->sleep(7 * 86400 - 1);
        $this->confirm('url-token');
        self::assertTrue($subscription->isConfirmed());
        self::assertEquals($this->clock->now(), $subscription->confirmedAt());
        self::assertNull($subscription->confirmationHash());
        self::assertNull($subscription->confirmationExpiresAt());

        $this->expectException(RecipientLinkInvalid::class);
        $this->confirm('url-token');
    }

    #[Test]
    public function it_should_refuse_an_expired_or_unknown_link(): void
    {
        $this->attach('ops@example.com');
        $this->clock->sleep(7 * 86400);

        foreach (['url-token', 'unknown'] as $token) {
            try {
                $this->confirm($token);
                self::fail($token);
            } catch (RecipientLinkInvalid $refusal) {
                self::assertSame('recipient_link_invalid', $refusal->ruleKey());
            }
        }

        self::assertFalse($this->subscriptions->subscriptions[0]->isConfirmed());
    }

    #[Test]
    public function it_should_send_a_new_link_while_unconfirmed_only(): void
    {
        $this->attach('ops@example.com');
        $subscription = $this->subscriptions->subscriptions[0];
        $subscription->releaseEvents();
        $this->clock->sleep(86400);

        $this->attach('ops@example.com', 'weekly');
        self::assertCount(1, $this->subscriptions->subscriptions);
        self::assertCount(1, $this->admission->admitted, 'Admitted once.');
        self::assertEquals(new \DateTimeImmutable('2026-11-21 09:00:00'), $subscription->confirmationExpiresAt(), 'A new week.');
        self::assertCount(1, $subscription->releaseEvents());
        self::assertSame(Frequency::Immediate, $subscription->frequency(), 'Its frequency stays.');

        $this->confirm('url-token');
        $this->attach('ops@example.com');
        self::assertSame([], $subscription->releaseEvents(), 'Confirmed: nothing sent again.');
        self::assertTrue($subscription->isConfirmed());

        $self = Subscription::subscribe(Uuid::v7(), Uuid::fromString(self::TEAM), null, Uuid::v7(), Frequency::Daily, $this->clock->now());
        self::assertTrue($self->isConfirmed());
        self::assertEquals($this->clock->now(), $self->confirmedAt());
        self::assertNull($self->addedBy());
        self::assertSame([], $self->releaseEvents());
    }

    #[Test]
    public function it_should_count_each_attached_account_once_against_the_quota(): void
    {
        $this->quota = 1;
        $this->attach('ops@example.com');
        $this->attach('ops@example.com', project: self::OTHER_PROJECT);
        self::assertCount(2, $this->subscriptions->subscriptions, 'The same account on another project counts once.');

        try {
            $this->attach('second@example.com');
            self::fail('Quota.');
        } catch (RecipientQuotaReached $refusal) {
            self::assertSame('quota', $refusal->ruleKey());
            self::assertSame('Quota "max_notification_only_accounts" of 1 reached.', $refusal->getMessage());
        }
        self::assertCount(2, $this->admission->admitted);

        $this->quota = null;
        $this->attach('second@example.com');
        self::assertCount(3, $this->subscriptions->subscriptions, 'Unlimited.');
        self::assertSame([self::OPS, self::SECOND], $this->subscriptions->attachedRecipients(Uuid::fromString(self::TEAM)));
    }

    #[Test]
    public function it_should_refuse_a_manager_without_the_permission_before_anything(): void
    {
        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsSubscribe];
        try {
            $this->attach('ops@example.com');
            self::fail('403');
        } catch (PermissionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->visible = [self::OTHER_PROJECT];
        try {
            $this->attach('ops@example.com');
            self::fail('404');
        } catch (ResourceNotFound) {
            $this->addToAssertionCount(1);
        }

        self::assertSame([], $this->accounts->asked);
        self::assertSame([], $this->lock->keys);
    }

    #[Test]
    public function it_should_refuse_a_frequency_that_tells_nothing(): void
    {
        foreach (['off', 'hourly'] as $frequency) {
            try {
                $this->attach('ops@example.com', $frequency);
                self::fail($frequency);
            } catch (InvalidNotificationSetting) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame([], $this->admission->admitted);
        $this->expectExceptionObject(new InvalidNotificationSetting('A recipient hears at once or in a digest.'));
        Subscription::attach(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), Uuid::v7(), Frequency::Off, Uuid::v7(), 't', 'h:t', $this->clock->now());
    }

    #[Test]
    public function it_should_detach_an_attached_recipient_only(): void
    {
        $this->attach('ops@example.com');
        $attached = $this->subscriptions->subscriptions[0];
        $own = Subscription::subscribe(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), Uuid::v7(), Frequency::Daily, $this->clock->now());
        $fleet = Subscription::subscribe(Uuid::v7(), Uuid::fromString(self::TEAM), null, Uuid::v7(), Frequency::Daily, $this->clock->now());
        $this->subscriptions->add($own);
        $this->subscriptions->add($fleet);
        $detach = new DetachRecipientHandler($this->subscriptions, $this->access());

        foreach ([$own->id()->toRfc4122(), $fleet->id()->toRfc4122(), Uuid::v7()->toRfc4122(), 'nope'] as $id) {
            try {
                $detach(new DetachRecipient($id));
                self::fail($id);
            } catch (ResourceNotFound) {
                $this->addToAssertionCount(1);
            }
        }

        $this->permissions->team[self::TEAM] = [];
        try {
            $detach(new DetachRecipient($attached->id()->toRfc4122()));
            self::fail('403');
        } catch (PermissionDenied) {
            self::assertCount(3, $this->subscriptions->subscriptions);
        }

        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsManage];
        $detach(new DetachRecipient($attached->id()->toRfc4122()));
        self::assertSame([$own, $fleet], $this->subscriptions->subscriptions);

        $command = new DetachRecipient('s-1');
        self::assertSame(['notification.detach_recipient', 'notification_subscription', 's-1'], [$command->auditAction()->name, $command->auditTarget()->type, $command->auditTarget()->id()]);
        $attach = new AttachRecipient(self::TEAM, self::PROJECT, 'ops@example.com', 'daily');
        self::assertSame(['notification.attach_recipient', 'project', self::PROJECT, '{"frequency":"daily"}'], [$attach->auditAction()->name, $attach->auditTarget()->type, $attach->auditTarget()->id(), $attach->auditContext()->toJson()]);
        self::assertSame('notification.confirm_recipient', new ConfirmRecipient('t')->auditAction()->name);
    }

    #[Test]
    public function it_should_send_the_link_to_an_active_account_only(): void
    {
        $sender = new RecordingConfirmations();
        $send = new SendRecipientConfirmation($this->queries, $sender);

        $send(new RecipientAttached('s-1', self::TEAM, self::OPS, 'token-1'));
        $send(new RecipientAttached('s-2', self::TEAM, self::SECOND, 'token-2'));
        $send(new RecipientAttached('s-3', self::TEAM, Uuid::v7()->toRfc4122(), 'token-3'));

        self::assertSame([['ops@example.com', 'Platform', 'token-1']], $sender->sent);
    }

    #[Test]
    public function it_should_tell_nothing_to_a_recipient_not_confirmed_yet(): void
    {
        $rules = new InMemoryRules();
        $rules->add(NotificationRule::define(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), null, 'Rule', [NotificationEvent::Outdated], new RuleThresholds(), [], true, $this->clock->now()));
        $this->attach('ops@example.com');
        $deliveries = new InMemoryDeliveries();
        $writer = new NoticeWriter($rules, new InMemoryWebhooks(), $this->subscriptions, new InMemoryNotices(), $deliveries, $this->queries, $this->clock);
        $recipients = new Recipients($this->subscriptions, $this->queries);

        $writer->write(new Occurrence(self::TEAM, new NotificationSubject(NotificationEvent::Outdated, self::PROJECT), 'k1', []));
        self::assertSame([], self::targets($deliveries));
        self::assertNull($recipients->subscription(self::OPS, Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT)));

        $this->confirm('url-token');
        $writer->write(new Occurrence(self::TEAM, new NotificationSubject(NotificationEvent::Outdated, self::PROJECT), 'k2', []));
        self::assertSame([self::OPS], self::targets($deliveries));
        self::assertNotNull($recipients->subscription(self::OPS, Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT)));
    }

    /**
     * @return list<string>
     */
    private static function targets(InMemoryDeliveries $deliveries): array
    {
        return array_map(static fn (Delivery $d): string => $d->target(), $deliveries->deliveries);
    }

    private function attach(string $email, string $frequency = 'immediate', string $project = self::PROJECT): void
    {
        new AttachRecipientHandler($this->access(), $this->lock, $this->accounts, $this->admission, $this->subscriptions, $this->queries, FixedActor::user(self::MANAGER), new FixedTokens(), new PrefixTokenHasher(), $this->clock)(new AttachRecipient(self::TEAM, $project, $email, $frequency));
    }

    private function confirm(string $token): void
    {
        new ConfirmRecipientHandler($this->subscriptions, new PrefixTokenHasher(), $this->clock)(new ConfirmRecipient($token));
    }

    private function access(): NotificationAccess
    {
        return new NotificationAccess($this->queries, $this->permissions);
    }
}
