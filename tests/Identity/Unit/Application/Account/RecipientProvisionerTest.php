<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Account;

use Identity\Application\Account\RecipientProvisioner;
use Identity\Domain\Model\AccountNotAssignable;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Contract\Identity\RecipientAccount;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\RecordingLock;
use Tests\Identity\Support\Users;

/**
 * Identity's port for a confirmed notification recipient: an unknown email becomes a notification-only account, an
 * existing one is returned telling whether it signs in; a deactivated or deleted one is refused.
 */
final class RecipientProvisionerTest extends TestCase
{
    #[Test]
    public function it_should_create_a_notification_only_account_for_an_unknown_email(): void
    {
        [$users, $lock, $clock] = [new InMemoryUsers(), new RecordingLock(), new MockClock('2026-11-13 09:00')];

        $account = new RecipientProvisioner($users, $lock, $clock)->recipientAccount(' Ops@Example.com ', Users::THIRD_ID);

        self::assertEquals(new RecipientAccount(Users::THIRD_ID, false), $account);
        self::assertSame(['identity.email:ops@example.com'], $lock->keys);
        $user = $users->users[Users::THIRD_ID];
        self::assertSame('ops@example.com', $user->email()->value());
        self::assertTrue($user->isNotificationOnly());
        self::assertFalse($user->privileges()->has(Permission::AccountLogin));
        self::assertTrue($user->awaitsPassword());
        self::assertFalse($user->canLogIn());
        self::assertFalse($user->maySetPassword(), 'Never a password link.');
        self::assertTrue($user->status()->isActive());
        self::assertEquals($clock->now(), $user->createdAt());
    }

    #[Test]
    public function it_should_return_an_existing_account_telling_whether_it_signs_in(): void
    {
        $recipient = User::notificationOnly(UserId::fromString(Users::OTHER_ID), EmailAddress::of('ops@example.com'), new \DateTimeImmutable());
        $users = new InMemoryUsers($recipient, Users::member(Users::ID, 'jane@example.com'));
        $provisioner = new RecipientProvisioner($users, new RecordingLock(), new MockClock());

        self::assertEquals(new RecipientAccount(Users::OTHER_ID, false), $provisioner->recipientAccount('ops@example.com', Users::THIRD_ID));
        self::assertEquals(new RecipientAccount(Users::ID, true), $provisioner->recipientAccount('jane@example.com', Users::THIRD_ID));
        self::assertEquals(new RecipientAccount(Users::ID, true), new RecipientProvisioner(new InMemoryUsers(Users::superAdmin(Users::ID, 'root@example.com')), new RecordingLock(), new MockClock())->recipientAccount('root@example.com', Users::THIRD_ID));
        self::assertCount(2, $users->users);
    }

    #[Test]
    public function it_should_refuse_a_deactivated_or_deleted_account(): void
    {
        foreach (['deactivate', 'delete'] as $gesture) {
            $existing = User::notificationOnly(UserId::fromString(Users::OTHER_ID), EmailAddress::of('ops@example.com'), new \DateTimeImmutable());
            $existing->{$gesture}(new \DateTimeImmutable());
            $users = new InMemoryUsers($existing);

            try {
                new RecipientProvisioner($users, new RecordingLock(), new MockClock())->recipientAccount('ops@example.com', Users::THIRD_ID);
                self::fail($gesture);
            } catch (AccountNotAssignable) {
                self::assertCount(1, $users->users, $gesture);
            }
        }
    }

    #[Test]
    public function it_should_promote_a_notification_only_account_only(): void
    {
        $recipient = User::notificationOnly(UserId::fromString(Users::OTHER_ID), EmailAddress::of('ops@example.com'), new \DateTimeImmutable());
        $recipient->promote();
        self::assertFalse($recipient->isNotificationOnly());
        self::assertTrue($recipient->maySetPassword(), 'A setup link may now be used.');

        $root = Users::superAdmin();
        self::assertFalse($root->isNotificationOnly());
        $root->promote();
        self::assertSame(Users::superAdmin()->privileges()->has(Permission::AccountLogin), $root->privileges()->has(Permission::AccountLogin));
    }
}
