<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Command;

use Identity\Application\Command\Account\ActivateInvitedAccount;
use Identity\Application\Command\Account\Handler\ActivateInvitedAccountHandler;
use Identity\Application\Command\Administration\Handler\InviteUserHandler;
use Identity\Application\Command\Administration\InviteUser;
use Identity\Application\Command\Password\Handler\RequestPasswordResetHandler;
use Identity\Application\Command\Password\Handler\ResetPasswordHandler;
use Identity\Application\Command\Password\RequestPasswordReset;
use Identity\Application\Command\Password\ResetPassword;
use Identity\Application\EventHandler\SendAccountSetupLink;
use Identity\Application\Port\AccountSetupSender;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Event\AccountSetupRequested;
use Identity\Domain\Model\AccountAlreadyActive;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Contract\Organization\FindPendingInvitation;
use Shared\Application\Contract\Organization\PendingInvitation;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedRequest;
use Tests\Identity\Support\FixedTokens;
use Tests\Identity\Support\Grants;
use Tests\Identity\Support\InMemoryResetRequests;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\PrefixPasswordHasher;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Identity\Support\RecordingLock;
use Tests\Identity\Support\ScriptedThrottle;
use Tests\Identity\Support\Users;
use Tests\Organization\Support\FixedQueries;

final class InvitedAccountHandlersTest extends TestCase
{
    private const string PASSWORD = 'a long enough passphrase';
    private const string REQUEST = '019a6b2c-6f00-7a11-8b22-3c44d55e6f01';

    #[Test]
    public function it_should_invite_an_account_with_a_setup_link(): void
    {
        [$users, $requests, $lock] = [new InMemoryUsers(), new InMemoryResetRequests(), new RecordingLock()];
        $grants = new Grants([Permission::InstanceUsersManage]);
        $this->invite($users, $requests, $lock, $grants)(new InviteUser(Users::ID, self::REQUEST, 'Jane@Example.com'));

        $user = $users->find(UserId::fromString(Users::ID));
        self::assertNotNull($user);
        self::assertSame('jane@example.com', $user->email()->value());
        self::assertTrue($user->awaitsPassword());
        self::assertSame(['identity.email:jane@example.com'], $lock->keys);
        self::assertSame([Permission::InstanceUsersManage], $grants->asked);

        $request = $requests->find(Uuid::fromString(self::REQUEST));
        self::assertNotNull($request);
        self::assertTrue($request->userId()->equals(UserId::fromString(Users::ID)));
        self::assertSame('h:url-token', new \ReflectionProperty(PasswordResetRequest::class, 'tokenHash')->getValue($request), 'Only the hash of the token is kept.');
        self::assertEquals([new AccountSetupRequested(self::REQUEST, Users::ID, 'url-token')], $request->releaseEvents());
        self::assertTrue($request->isUsableAt(new \DateTimeImmutable('2026-11-16 07:59:59')));
    }

    #[Test]
    public function it_should_refuse_a_taken_email_or_an_actor_without_the_permission(): void
    {
        $users = new InMemoryUsers(Users::member());
        try {
            $this->invite($users, new InMemoryResetRequests(), new RecordingLock(), new Grants([Permission::InstanceUsersManage]))(new InviteUser(Users::OTHER_ID, self::REQUEST, 'jane@example.com'));
            self::fail('Taken.');
        } catch (EmailAlreadyRegistered) {
        }

        $lock = new RecordingLock();
        try {
            $this->invite(new InMemoryUsers(), new InMemoryResetRequests(), $lock, new Grants())(new InviteUser(Users::OTHER_ID, self::REQUEST, 'john@example.com'));
            self::fail('Denied.');
        } catch (PermissionDenied) {
        }
        self::assertSame([], $lock->keys, 'Checked before any lock.');
    }

    #[Test]
    public function it_should_let_an_invited_account_choose_its_first_password(): void
    {
        $clock = new MockClock('2026-11-09 08:00:00');
        $user = $this->invited();
        $requests = new InMemoryResetRequests();
        $request = PasswordResetRequest::issueAccountSetup(Uuid::fromString(self::REQUEST), $user->id(), 't', 'h:t', $clock->now());
        $requests->add($request);

        new ResetPasswordHandler($requests, new InMemoryUsers($user), new PrefixPasswordHasher(), $clock)(new ResetPassword(self::REQUEST, self::PASSWORD));

        self::assertTrue($user->canLogIn());
        self::assertSame('hashed:'.self::PASSWORD, $user->credentials()->passwordHash());
        self::assertFalse($request->isUsableAt($clock->now()));
    }

    #[Test]
    public function it_should_send_a_new_link_to_an_invited_account_that_forgot_it(): void
    {
        $requests = new InMemoryResetRequests();
        new RequestPasswordResetHandler(new InMemoryUsers($this->invited()), $requests, new FixedTokens(), new PrefixTokenHasher(), new ScriptedThrottle(), new FixedRequest(), new MockClock())(new RequestPasswordReset(self::REQUEST, 'jane@example.com'));

        self::assertCount(1, $requests->requests, 'The setup link may have expired: a reset link sets the first password too.');
    }

    #[Test]
    public function it_should_complete_an_invited_account_from_a_team_invitation(): void
    {
        $user = $this->invited();
        $users = new InMemoryUsers($user);
        $this->activate($users)(new ActivateInvitedAccount(Users::OTHER_ID, 'invitation', self::PASSWORD));

        self::assertCount(1, $users->users, 'The invited account is completed, never duplicated.');
        self::assertTrue($user->canLogIn());
        self::assertSame('hashed:'.self::PASSWORD, $user->credentials()->passwordHash());

        $disabled = $this->invited();
        $disabled->deactivate(new \DateTimeImmutable());
        $this->expectException(AccountAlreadyActive::class);
        $this->activate(new InMemoryUsers($disabled))(new ActivateInvitedAccount(Users::OTHER_ID, 'invitation', self::PASSWORD));
    }

    #[Test]
    public function it_should_send_the_setup_link_after_the_commit(): void
    {
        $sender = new class implements AccountSetupSender {
            /** @var list<array{string, string}> */
            public array $sent = [];

            public function send(User $user, string $token): void
            {
                $this->sent[] = [$user->id()->toString(), $token];
            }
        };
        $handler = new SendAccountSetupLink(new InMemoryUsers($this->invited()), $sender);
        $handler(new AccountSetupRequested(self::REQUEST, Users::ID, 'plain'));
        $handler(new AccountSetupRequested(self::REQUEST, Users::OTHER_ID, 'gone'));

        self::assertSame([[Users::ID, 'plain']], $sender->sent);
    }

    private function invited(): User
    {
        return User::invite(UserId::fromString(Users::ID), EmailAddress::of('jane@example.com'), new \DateTimeImmutable('2026-11-09 08:00:00'));
    }

    private function invite(InMemoryUsers $users, InMemoryResetRequests $requests, RecordingLock $lock, Grants $grants): InviteUserHandler
    {
        return new InviteUserHandler(new AccountAccess(FixedActor::user(Users::OTHER_ID), $grants, $users), $users, $requests, $lock, new FixedTokens(), new PrefixTokenHasher(), new MockClock('2026-11-09 08:00:00'));
    }

    private function activate(InMemoryUsers $users): ActivateInvitedAccountHandler
    {
        $queries = new FixedQueries([FindPendingInvitation::class => static fn (): PendingInvitation => new PendingInvitation(
            'invitation', '019a6b2c-2f00-7a11-8b22-3c44d55e6f01', 'jane@example.com', 'Platform', null, 'team_member', new \DateTimeImmutable('2026-11-16'),
        )]);

        return new ActivateInvitedAccountHandler($queries, $users, new PrefixPasswordHasher(), new RecordingLock(), new MockClock('2026-11-09 08:00:00'));
    }
}
