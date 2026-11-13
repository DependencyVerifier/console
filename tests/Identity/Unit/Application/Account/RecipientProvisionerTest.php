<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Account;

use Identity\Application\Account\RecipientProvisioner;
use Identity\Domain\Model\AccountNotAssignable;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\RecipientSignsIn;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Contract\Identity\ProvisionedAccount;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\RecordingLock;
use Tests\Identity\Support\Users;

/**
 * Identity's port for the notification recipients: an unknown email becomes a notification-only account, an existing
 * one is reused; an account that signs in, a deactivated or deleted one is refused.
 */
final class RecipientProvisionerTest extends TestCase
{
    #[Test]
    public function it_should_create_a_notification_only_account_for_an_unknown_email(): void
    {
        [$users, $lock, $clock] = [new InMemoryUsers(), new RecordingLock(), new MockClock('2026-11-13 09:00')];

        $account = new RecipientProvisioner($users, $lock, $clock)->notificationAccount(' Ops@Example.com ', Users::THIRD_ID);

        self::assertEquals(new ProvisionedAccount(Users::THIRD_ID, 'ops@example.com', true), $account);
        self::assertSame(['identity.email:ops@example.com'], $lock->keys);
        $user = $users->users[Users::THIRD_ID];
        self::assertFalse($user->privileges()->has(Permission::AccountLogin));
        self::assertFalse($user->isSuperAdmin());
        self::assertTrue($user->awaitsPassword());
        self::assertFalse($user->canLogIn());
        self::assertFalse($user->maySetPassword(), 'Never a password link.');
        self::assertTrue($user->status()->isActive());
        self::assertEquals($clock->now(), $user->createdAt());
    }

    #[Test]
    public function it_should_reuse_a_notification_only_account(): void
    {
        $existing = User::notificationOnly(UserId::fromString(Users::OTHER_ID), EmailAddress::of('ops@example.com'), new \DateTimeImmutable());
        $users = new InMemoryUsers($existing);

        $account = new RecipientProvisioner($users, new RecordingLock(), new MockClock())->notificationAccount('ops@example.com', Users::THIRD_ID);

        self::assertEquals(new ProvisionedAccount(Users::OTHER_ID, 'ops@example.com', false), $account);
        self::assertCount(1, $users->users);
    }

    #[Test]
    public function it_should_refuse_an_account_that_signs_in(): void
    {
        $users = new InMemoryUsers(Users::member(Users::OTHER_ID, 'jane@example.com'));

        $this->expectException(RecipientSignsIn::class);
        new RecipientProvisioner($users, new RecordingLock(), new MockClock())->notificationAccount('jane@example.com', Users::THIRD_ID);
    }

    #[Test]
    public function it_should_refuse_a_deactivated_or_deleted_account(): void
    {
        foreach (['deactivate', 'delete'] as $gesture) {
            $existing = User::notificationOnly(UserId::fromString(Users::OTHER_ID), EmailAddress::of('ops@example.com'), new \DateTimeImmutable());
            $existing->{$gesture}(new \DateTimeImmutable());
            $users = new InMemoryUsers($existing);

            try {
                new RecipientProvisioner($users, new RecordingLock(), new MockClock())->notificationAccount('ops@example.com', Users::THIRD_ID);
                self::fail($gesture);
            } catch (AccountNotAssignable) {
                self::assertCount(1, $users->users, $gesture);
            }
        }

        self::assertSame('recipient_signs_in', new RecipientSignsIn()->ruleKey());
    }
}
