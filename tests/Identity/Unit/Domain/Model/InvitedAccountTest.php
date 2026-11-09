<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Domain\Model;

use Identity\Domain\Event\AccountSetupRequested;
use Identity\Domain\Event\PasswordChanged;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\Privileges;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\Users;

/**
 * An account created from /admin/users: no password until its holder chooses one through an emailed link.
 */
final class InvitedAccountTest extends TestCase
{
    #[Test]
    public function it_should_wait_for_its_first_password(): void
    {
        $user = $this->invited();

        self::assertTrue($user->awaitsPassword());
        self::assertFalse($user->credentials()->hasPassword());
        self::assertFalse($user->canLogIn(), 'No password yet.');
        self::assertTrue($user->maySetPassword());
        self::assertFalse($user->isSuperAdmin());
        self::assertSame('jane@example.com', $user->email()->value());
        self::assertEquals(new \DateTimeImmutable('2026-11-09 08:00:00'), $user->createdAt());

        $user->choosePassword('first-hash');

        self::assertFalse($user->awaitsPassword());
        self::assertTrue($user->canLogIn());
        self::assertSame('first-hash', $user->credentials()->passwordHash());
        self::assertSame(1, $user->sessionVersions()->authVersion(), 'No session to revoke yet.');
        self::assertSame([], $user->releaseEvents(), 'No "password changed" alert for a first password.');
    }

    #[Test]
    public function it_should_change_an_existing_password_like_any_change(): void
    {
        $user = Users::member();
        $user->choosePassword('new-hash');

        self::assertSame('new-hash', $user->credentials()->passwordHash());
        self::assertSame(2, $user->sessionVersions()->authVersion());
        self::assertEquals([new PasswordChanged(Users::ID)], $user->releaseEvents());
    }

    #[Test]
    public function it_should_refuse_a_password_link_to_a_disabled_or_notification_only_account(): void
    {
        $disabled = $this->invited();
        $disabled->deactivate(new \DateTimeImmutable());
        self::assertFalse($disabled->maySetPassword());

        $notificationOnly = $this->invited();
        new \ReflectionProperty(User::class, 'privileges')->setValue($notificationOnly, Privileges::none());
        self::assertFalse($notificationOnly->maySetPassword());
    }

    #[Test]
    public function it_should_issue_a_setup_link_for_seven_days(): void
    {
        $now = new \DateTimeImmutable('2026-11-09 08:00:00');
        $id = Uuid::v7();
        $request = PasswordResetRequest::issueAccountSetup($id, UserId::fromString(Users::ID), 'plain', 'hash', $now);

        self::assertTrue($request->isUsableAt($now->modify('+7 days -1 second')));
        self::assertFalse($request->isUsableAt($now->modify('+7 days')));
        self::assertEquals([new AccountSetupRequested($id->toRfc4122(), Users::ID, 'plain')], $request->releaseEvents());

        $reset = PasswordResetRequest::issue(Uuid::v7(), UserId::fromString(Users::ID), 'plain', 'hash2', $now);
        self::assertFalse($reset->isUsableAt($now->modify('+30 minutes')), 'A reset link keeps its 30 minutes.');
    }

    #[Test]
    public function it_should_never_serialize_the_setup_token(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new AccountSetupRequested('r', 'u', 'plain'));
    }

    private function invited(): User
    {
        return User::invite(UserId::fromString(Users::ID), EmailAddress::of('Jane@Example.com'), new \DateTimeImmutable('2026-11-09 08:00:00'));
    }
}
