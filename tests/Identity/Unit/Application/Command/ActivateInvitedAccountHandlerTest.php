<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Command;

use Identity\Application\Command\Account\ActivateInvitedAccount;
use Identity\Application\Command\Account\Handler\ActivateInvitedAccountHandler;
use Identity\Domain\Model\AccountAlreadyActive;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\PasswordRejected;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Contract\Organization\FindPendingInvitation;
use Shared\Application\Contract\Organization\PendingInvitation;
use Symfony\Component\Clock\MockClock;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\PrefixPasswordHasher;
use Tests\Identity\Support\RecordingLock;
use Tests\Identity\Support\Users;
use Tests\Organization\Support\FixedQueries;

/**
 * The email comes from the pending invitation, never from the client; an account that already exists is refused
 * under the email lock (two activations racing for the same email).
 */
final class ActivateInvitedAccountHandlerTest extends TestCase
{
    private const string PASSWORD = 'a long enough passphrase';
    private const string INVITATION = '019a6b2c-5f00-7a11-8b22-3c44d55e6f01';

    #[Test]
    public function it_should_create_the_account_of_the_invited_email(): void
    {
        [$users, $lock] = [new InMemoryUsers(), new RecordingLock()];
        $this->handler($users, $lock)(new ActivateInvitedAccount(Users::ID, self::INVITATION, self::PASSWORD));

        $user = $users->findByEmail(EmailAddress::of('jane@example.com'));
        self::assertNotNull($user);
        self::assertSame(Users::ID, $user->id()->toString());
        self::assertSame(['identity.email:jane@example.com'], $lock->keys);
    }

    #[Test]
    public function it_should_refuse_an_email_that_already_has_an_account(): void
    {
        $users = new InMemoryUsers(Users::member(email: 'jane@example.com'));

        try {
            $this->handler($users, new RecordingLock())(new ActivateInvitedAccount(Users::uuid()->toRfc4122(), self::INVITATION, self::PASSWORD));
            self::fail('Already active.');
        } catch (AccountAlreadyActive $refused) {
            self::assertSame('account_already_active', $refused->ruleKey());
        }

        self::assertCount(1, $users->users);
    }

    #[Test]
    public function it_should_promote_a_notification_only_account_keeping_its_id(): void
    {
        $recipient = User::notificationOnly(UserId::fromString(Users::OTHER_ID), EmailAddress::of('jane@example.com'), new \DateTimeImmutable());
        $users = new InMemoryUsers($recipient);

        $this->handler($users, new RecordingLock())(new ActivateInvitedAccount(Users::uuid()->toRfc4122(), self::INVITATION, self::PASSWORD));

        self::assertCount(1, $users->users);
        self::assertFalse($recipient->isNotificationOnly());
        self::assertTrue($recipient->canLogIn(), 'Promoted, with its password: it signs in and accepts.');
    }

    #[Test]
    public function it_should_not_promote_a_deactivated_notification_only_account(): void
    {
        $recipient = User::notificationOnly(UserId::fromString(Users::OTHER_ID), EmailAddress::of('jane@example.com'), new \DateTimeImmutable());
        $recipient->deactivate(new \DateTimeImmutable());

        try {
            $this->handler(new InMemoryUsers($recipient), new RecordingLock())(new ActivateInvitedAccount(Users::uuid()->toRfc4122(), self::INVITATION, self::PASSWORD));
            self::fail('Deactivated.');
        } catch (AccountAlreadyActive) {
            self::assertTrue($recipient->isNotificationOnly());
        }
    }

    #[Test]
    public function it_should_refuse_a_password_outside_the_policy_before_anything_else(): void
    {
        [$users, $lock] = [new InMemoryUsers(), new RecordingLock()];

        try {
            $this->handler($users, $lock)(new ActivateInvitedAccount(Users::ID, self::INVITATION, 'too short'));
            self::fail('Weak password.');
        } catch (PasswordRejected) {
        }

        self::assertSame([], $users->users);
        self::assertSame([], $lock->keys);
    }

    private function handler(InMemoryUsers $users, RecordingLock $lock): ActivateInvitedAccountHandler
    {
        $queries = new FixedQueries([FindPendingInvitation::class => static fn (): PendingInvitation => new PendingInvitation(
            self::INVITATION, '019a6b2c-2f00-7a11-8b22-3c44d55e6f01', 'Jane@Example.com', 'Platform', null, 'team_member', new \DateTimeImmutable('2026-11-16'),
        )]);

        return new ActivateInvitedAccountHandler($queries, $users, new PrefixPasswordHasher(), $lock, new MockClock('2026-11-09 08:00:00'));
    }
}
