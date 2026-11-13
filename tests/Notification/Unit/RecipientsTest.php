<?php

declare(strict_types=1);

namespace Tests\Notification\Unit;

use Notification\Application\Command\Recipient\AttachRecipient;
use Notification\Application\Command\Recipient\CancelRecipientInvitation;
use Notification\Application\Command\Recipient\ConfirmRecipient;
use Notification\Application\Command\Recipient\DetachRecipient;
use Notification\Application\Command\Recipient\Handler\AttachRecipientHandler;
use Notification\Application\Command\Recipient\Handler\CancelRecipientInvitationHandler;
use Notification\Application\Command\Recipient\Handler\ConfirmRecipientHandler;
use Notification\Application\Command\Recipient\Handler\DetachRecipientHandler;
use Notification\Application\EventHandler\SendRecipientConfirmation;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Event\RecipientInvited;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\InvalidNotificationSetting;
use Notification\Domain\Model\RecipientInvitation;
use Notification\Domain\Model\RecipientInvitationStatus;
use Notification\Domain\Model\RecipientLinkInvalid;
use Notification\Domain\Model\RecipientQuotaReached;
use Notification\Domain\Model\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\FilterRecipients;
use Shared\Application\Contract\Organization\FindTeamNames;
use Shared\Application\Contract\Organization\GetTeamAccess;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\TeamAccess;
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
use Tests\Notification\Support\InMemoryRecipientInvitations;
use Tests\Notification\Support\InMemorySubscriptions;
use Tests\Notification\Support\RecordingAdmission;
use Tests\Notification\Support\RecordingConfirmations;
use Tests\Organization\Support\FixedQueries;

use function Symfony\Component\String\u;

/**
 * The notification-only recipients: a manager invites an address (nothing else exists, the same answer whatever the
 * address), its holder confirms (account, membership, subscription), a manager cancels or detaches.
 */
final class RecipientsTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f11';
    private const string OTHER_PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f12';
    private const string MANAGER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f50';
    private const string OPS = '019a6b2c-1f00-7a11-8b22-3c44d55e6f51';
    private const string JANE = '019a6b2c-1f00-7a11-8b22-3c44d55e6f52';
    private const string JOHN = '019a6b2c-1f00-7a11-8b22-3c44d55e6f53';

    private InMemoryRecipientInvitations $invitations;
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
        $this->invitations = new InMemoryRecipientInvitations();
        $this->subscriptions = new InMemorySubscriptions();
        // ops: a notification-only account already; jane signs in and sees the project; john signs in, does not see it.
        $this->accounts = new FixedRecipientAccounts(['ops@example.com' => [self::OPS, false], 'jane@example.com' => [self::JANE, true], 'john@example.com' => [self::JOHN, true]]);
        $this->admission = new RecordingAdmission();
        $this->lock = new RecordingLock();
        $this->permissions = new GrantedPermissions();
        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsManage];
        $this->clock = new MockClock('2026-11-13 09:00:00');
        $this->queries = new FixedQueries([
            GetTeamAccess::class => static fn (object $q): TeamAccess => new TeamAccess(self::TEAM, 'Platform', 'team_member', false),
            GetVisibleProjects::class => fn (object $q): VisibleProjects => new VisibleProjects(false, $this->visible),
            GetTeamQuota::class => fn (object $q): ?int => $q instanceof GetTeamQuota && 'max_notification_only_accounts' === $q->quota && self::TEAM === $q->teamId ? $this->quota : throw new \LogicException('Unexpected quota.'),
            FindTeamNames::class => static fn (object $q): array => $q instanceof FindTeamNames ? array_intersect_key([self::TEAM => 'Platform'], array_flip($q->teamIds)) : [],
            FilterRecipients::class => static fn (object $q): array => $q instanceof FilterRecipients ? array_values(array_diff($q->userIds, [self::JOHN])) : [],
        ]);
    }

    #[Test]
    public function it_should_invite_an_address_and_nothing_else(): void
    {
        foreach ([' Ops@Example.com ', 'jane@example.com', 'john@example.com', 'nobody@example.com'] as $email) {
            $this->attach($email, 'daily');
        }

        self::assertSame([], $this->accounts->asked, 'Identity is not asked: nothing tells whether an account exists.');
        self::assertSame([], $this->admission->admitted);
        self::assertSame([], $this->subscriptions->subscriptions);
        self::assertSame(['ops@example.com', 'jane@example.com', 'john@example.com', 'nobody@example.com'], array_map(static fn (RecipientInvitation $i): string => $i->email(), $this->invitations->invitations));
        self::assertSame(array_fill(0, 4, 'notification.recipients:'.self::TEAM), $this->lock->keys);

        $invitation = $this->invitations->invitations[0];
        self::assertSame([self::TEAM, self::PROJECT, Frequency::Daily, self::MANAGER, 'h:url-token', RecipientInvitationStatus::Pending], [$invitation->teamId()->toRfc4122(), $invitation->projectId()->toRfc4122(), $invitation->frequency(), $invitation->invitedBy()->toRfc4122(), $invitation->tokenHash(), $invitation->status()]);
        self::assertEquals(new \DateTimeImmutable('2026-11-20 09:00:00'), $invitation->expiresAt());
        self::assertEquals($this->clock->now(), $invitation->createdAt());
        self::assertNull($invitation->closedAt());
        self::assertEquals([new RecipientInvited($invitation->id()->toRfc4122(), self::TEAM, self::PROJECT, 'ops@example.com', 'url-token')], $invitation->releaseEvents());
    }

    #[Test]
    public function it_should_send_a_new_link_while_pending(): void
    {
        $this->attach('ops@example.com');
        $invitation = $this->invitations->invitations[0];
        $invitation->releaseEvents();

        $this->clock->sleep(8 * 86400);
        self::assertFalse($invitation->isPendingAt($this->clock->now()));
        $this->attach('OPS@example.com', 'weekly');

        self::assertCount(1, $this->invitations->invitations, 'Expired, sent again: never a second one.');
        self::assertTrue($invitation->isPendingAt($this->clock->now()));
        self::assertEquals(new \DateTimeImmutable('2026-11-28 09:00:00'), $invitation->expiresAt());
        self::assertSame(Frequency::Immediate, $invitation->frequency(), 'The first choice stays.');
        self::assertCount(1, $invitation->releaseEvents());

        $this->attach('ops@example.com', project: self::OTHER_PROJECT);
        self::assertCount(2, $this->invitations->invitations, 'Another project: another invitation.');
    }

    #[Test]
    public function it_should_count_the_invited_addresses_and_the_attached_accounts_against_the_quota(): void
    {
        $this->quota = 2;
        $this->subscriptions->add(Subscription::attach(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), Uuid::fromString(self::OPS), Frequency::Daily, Uuid::v7(), $this->clock->now()));
        $this->subscriptions->add(Subscription::subscribe(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), Uuid::v7(), Frequency::Daily, $this->clock->now()));
        $this->attach('a@example.com');
        $this->attach('a@example.com', project: self::OTHER_PROJECT);
        $this->attach('a@example.com');

        try {
            $this->attach('b@example.com');
            self::fail('Quota.');
        } catch (RecipientQuotaReached $refusal) {
            self::assertSame('quota', $refusal->ruleKey());
            self::assertSame('Quota "max_notification_only_accounts" of 2 reached.', $refusal->getMessage());
        }

        $this->clock->sleep(8 * 86400);
        $this->attach('b@example.com');
        self::assertCount(3, $this->invitations->invitations, 'An expired invitation counts no more.');

        $this->quota = null;
        $this->attach('c@example.com');
        self::assertCount(4, $this->invitations->invitations, 'Unlimited.');
    }

    #[Test]
    public function it_should_refuse_a_manager_without_the_permission_before_anything(): void
    {
        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsSubscribe];
        $this->assertThrows(PermissionDenied::class, fn () => $this->attach('ops@example.com'));
        $this->visible = [self::OTHER_PROJECT];
        $this->assertThrows(ResourceNotFound::class, fn () => $this->attach('ops@example.com'));

        self::assertSame([], $this->lock->keys);
        self::assertSame([], $this->invitations->invitations);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidInvitations(): iterable
    {
        yield 'off' => ['ops@example.com', 'off'];
        yield 'unknown frequency' => ['ops@example.com', 'hourly'];
        yield 'no at' => ['ops.example.com', 'daily'];
        yield 'no domain' => ['ops@example', 'daily'];
        yield 'spaces' => ['o ps@example.com', 'daily'];
        yield 'too long' => [u('a')->repeat(243)->toString().'@example.com', 'daily'];
    }

    #[Test]
    #[DataProvider('invalidInvitations')]
    public function it_should_refuse_an_invalid_invitation(string $email, string $frequency): void
    {
        $this->assertThrows(InvalidNotificationSetting::class, fn () => $this->attach($email, $frequency));
        self::assertSame([], $this->invitations->invitations);
    }

    #[Test]
    public function it_should_keep_the_longest_address_and_refuse_off_in_the_domain(): void
    {
        self::assertSame(254, u(RecipientInvitation::normalize(u('a')->repeat(242)->toString().'@example.com'))->length());

        $this->expectExceptionObject(new InvalidNotificationSetting('A recipient hears at once or in a digest.'));
        RecipientInvitation::issue(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'ops@example.com', Frequency::Off, Uuid::v7(), 't', 'h:t', $this->clock->now());
    }

    #[Test]
    public function it_should_attach_a_notification_only_account_once_its_holder_confirms(): void
    {
        $this->attach('new@example.com', 'weekly');
        $this->clock->sleep(7 * 86400 - 1);

        $this->confirm('url-token');

        $invitation = $this->invitations->invitations[0];
        [$team, $project, $user, $by] = $this->admission->admitted[0];
        self::assertSame([self::TEAM, self::PROJECT, self::MANAGER], [$team, $project, $by]);
        self::assertSame(['new@example.com'], $this->accounts->asked);
        self::assertSame(['notification.recipients:'.self::TEAM, 'notification.recipients:'.self::TEAM], $this->lock->keys);
        $subscription = $this->subscriptions->subscriptions[0];
        self::assertSame([$user, Frequency::Weekly, self::MANAGER, self::PROJECT], [$subscription->userId()->toRfc4122(), $subscription->frequency(), $subscription->addedBy()?->toRfc4122(), $subscription->projectId()?->toRfc4122()]);
        self::assertSame(RecipientInvitationStatus::Confirmed, $invitation->status());
        self::assertEquals($this->clock->now(), $invitation->closedAt());

        $this->assertThrows(RecipientLinkInvalid::class, fn () => $this->confirm('url-token'), 'Spent.');
        self::assertCount(1, $this->subscriptions->subscriptions);
    }

    #[Test]
    public function it_should_reuse_a_notification_only_account_and_its_subscription(): void
    {
        $this->subscriptions->add($existing = Subscription::attach(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), Uuid::fromString(self::OPS), Frequency::Daily, Uuid::v7(), $this->clock->now()));
        $this->attach('ops@example.com');

        $this->confirm('url-token');

        self::assertSame([[self::TEAM, self::PROJECT, self::OPS, self::MANAGER]], $this->admission->admitted);
        self::assertSame([$existing], $this->subscriptions->subscriptions);
        self::assertSame(RecipientInvitationStatus::Confirmed, $this->invitations->invitations[0]->status());
    }

    #[Test]
    public function it_should_never_attach_an_account_that_signs_in(): void
    {
        $this->attach('jane@example.com');
        $this->confirm('url-token');
        self::assertSame([], $this->admission->admitted, 'Never a membership given that way.');
        self::assertSame(self::JANE, $this->subscriptions->subscriptions[0]->userId()->toRfc4122(), 'Jane sees the project: subscribed, as she could herself.');
        self::assertSame(RecipientInvitationStatus::Confirmed, $this->invitations->invitations[0]->status());

        $this->attach('john@example.com');
        $this->invitations->invitations[1]->resend('john-token', 'h:john-token', $this->clock->now());
        $this->confirm('john-token');
        self::assertCount(1, $this->subscriptions->subscriptions, 'John does not see it: nothing.');
        self::assertSame(RecipientInvitationStatus::Declined, $this->invitations->invitations[1]->status());
        self::assertEquals($this->clock->now(), $this->invitations->invitations[1]->closedAt());
    }

    #[Test]
    public function it_should_refuse_an_unknown_expired_or_closed_link(): void
    {
        $this->attach('ops@example.com');
        $this->assertThrows(RecipientLinkInvalid::class, fn () => $this->confirm('unknown'));

        $this->clock->sleep(7 * 86400);
        $this->assertThrows(RecipientLinkInvalid::class, fn () => $this->confirm('url-token'), 'Expired.');

        $invitation = $this->invitations->invitations[0];
        $invitation->resend('t2', 'h:t2', $this->clock->now());
        $invitation->cancel($this->clock->now());
        $invitation->confirm($this->clock->now());
        self::assertSame(RecipientInvitationStatus::Cancelled, $invitation->status(), 'Closed once.');
        $this->assertThrows(RecipientLinkInvalid::class, fn () => $this->confirm('t2'), 'Cancelled.');

        self::assertSame([], $this->accounts->asked);
        self::assertSame('recipient_link_invalid', new RecipientLinkInvalid()->ruleKey());
    }

    #[Test]
    public function it_should_attach_nothing_when_the_manager_may_no_longer(): void
    {
        $this->attach('ops@example.com');
        $this->admission->refusal = PermissionDenied::for('project.notifications.manage');

        $this->assertThrows(PermissionDenied::class, fn () => $this->confirm('url-token'));

        self::assertSame([], $this->subscriptions->subscriptions);
        self::assertSame(RecipientInvitationStatus::Pending, $this->invitations->invitations[0]->status());
    }

    #[Test]
    public function it_should_cancel_a_pending_invitation(): void
    {
        $this->attach('ops@example.com');
        $invitation = $this->invitations->invitations[0];
        $cancel = new CancelRecipientInvitationHandler($this->invitations, $this->access(), $this->clock);

        foreach ([Uuid::v7()->toRfc4122(), 'nope'] as $id) {
            $this->assertThrows(ResourceNotFound::class, static fn () => $cancel(new CancelRecipientInvitation($id)));
        }

        $this->permissions->team[self::TEAM] = [];
        $this->assertThrows(PermissionDenied::class, static fn () => $cancel(new CancelRecipientInvitation($invitation->id()->toRfc4122())));
        self::assertSame(RecipientInvitationStatus::Pending, $invitation->status());

        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsManage];
        $cancel(new CancelRecipientInvitation($invitation->id()->toRfc4122()));
        self::assertSame(RecipientInvitationStatus::Cancelled, $invitation->status());
        self::assertEquals($this->clock->now(), $invitation->closedAt());

        $command = new CancelRecipientInvitation('i-1');
        self::assertSame(['notification.cancel_recipient_invitation', 'notification_recipient_invitation', 'i-1'], [$command->auditAction()->name, $command->auditTarget()->type, $command->auditTarget()->id()]);
    }

    #[Test]
    public function it_should_detach_an_attached_recipient_only(): void
    {
        $attached = Subscription::attach(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), Uuid::fromString(self::OPS), Frequency::Daily, Uuid::fromString(self::MANAGER), $this->clock->now());
        $own = Subscription::subscribe(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), Uuid::v7(), Frequency::Daily, $this->clock->now());
        $fleet = Subscription::subscribe(Uuid::v7(), Uuid::fromString(self::TEAM), null, Uuid::v7(), Frequency::Daily, $this->clock->now());
        foreach ([$attached, $own, $fleet] as $subscription) {
            $this->subscriptions->add($subscription);
        }
        self::assertNull($own->addedBy());
        $detach = new DetachRecipientHandler($this->subscriptions, $this->access());

        foreach ([$own->id()->toRfc4122(), $fleet->id()->toRfc4122(), Uuid::v7()->toRfc4122(), 'nope'] as $id) {
            $this->assertThrows(ResourceNotFound::class, static fn () => $detach(new DetachRecipient($id)), $id);
        }

        $this->permissions->team[self::TEAM] = [];
        $this->assertThrows(PermissionDenied::class, static fn () => $detach(new DetachRecipient($attached->id()->toRfc4122())));
        self::assertCount(3, $this->subscriptions->subscriptions);

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
    public function it_should_send_the_same_link_email_to_any_address(): void
    {
        $sender = new RecordingConfirmations();
        new SendRecipientConfirmation($this->queries, $sender)(new RecipientInvited('i-1', self::TEAM, self::PROJECT, 'ops@example.com', 'token-1'));

        self::assertSame([['ops@example.com', 'Platform', 'token-1']], $sender->sent);
    }

    /**
     * @param class-string<\Throwable> $expected
     * @param \Closure(): mixed        $gesture
     */
    private function assertThrows(string $expected, \Closure $gesture, string $message = ''): void
    {
        try {
            $gesture();
            self::fail($message ?: $expected);
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($expected, $thrown, $message);
        }
    }

    private function attach(string $email, string $frequency = 'immediate', string $project = self::PROJECT): void
    {
        new AttachRecipientHandler($this->access(), $this->lock, $this->invitations, $this->subscriptions, $this->queries, FixedActor::user(self::MANAGER), new FixedTokens(), new PrefixTokenHasher(), $this->clock)(new AttachRecipient(self::TEAM, $project, $email, $frequency));
    }

    private function confirm(string $token): void
    {
        new ConfirmRecipientHandler($this->invitations, $this->subscriptions, $this->accounts, $this->admission, $this->queries, $this->lock, new PrefixTokenHasher(), $this->clock)(new ConfirmRecipient($token));
    }

    private function access(): NotificationAccess
    {
        return new NotificationAccess($this->queries, $this->permissions);
    }
}
