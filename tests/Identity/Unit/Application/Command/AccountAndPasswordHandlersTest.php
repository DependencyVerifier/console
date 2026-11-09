<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Command;

use Identity\Application\Command\Account\CreateSuperAdmin;
use Identity\Application\Command\Account\Handler\CreateSuperAdminHandler;
use Identity\Application\Command\Password\ChangePassword;
use Identity\Application\Command\Password\Handler\ChangePasswordHandler;
use Identity\Application\Command\Password\Handler\RequestPasswordResetHandler;
use Identity\Application\Command\Password\Handler\ResetPasswordHandler;
use Identity\Application\Command\Password\RequestPasswordReset;
use Identity\Application\Command\Password\ResetPassword;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Event\PasswordResetRequested;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\PasswordRejected;
use Identity\Domain\Model\PasswordResetLinkInvalid;
use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Actor;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Security\Throttled;
use Shared\Application\Security\ThrottleName;
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

final class AccountAndPasswordHandlersTest extends TestCase
{
    private const string PASSWORD = 'a long enough passphrase';

    #[Test]
    public function it_should_create_a_super_admin_from_the_cli(): void
    {
        $users = new InMemoryUsers();
        $lock = new RecordingLock();
        $clock = new MockClock('2026-11-09 08:00:00');

        $this->createSuperAdmin($users, $lock, new FixedActor(Actor::cli()), $clock)(new CreateSuperAdmin(Users::ID, ' Root@Example.com ', self::PASSWORD));

        $user = $users->find(UserId::fromString(Users::ID));
        self::assertNotNull($user);
        self::assertTrue($user->isSuperAdmin());
        self::assertSame('root@example.com', $user->email()->value());
        self::assertSame('hashed:'.self::PASSWORD, $user->credentials()->passwordHash());
        self::assertEquals($clock->now(), $user->createdAt());
        self::assertSame(['identity.email:root@example.com'], $lock->keys);
    }

    #[Test]
    public function it_should_create_a_super_admin_from_the_cli_only(): void
    {
        $this->expectException(PermissionDenied::class);

        $this->createSuperAdmin(new InMemoryUsers(), new RecordingLock(), FixedActor::user())(new CreateSuperAdmin(Users::ID, 'root@example.com', self::PASSWORD));
    }

    #[Test]
    public function it_should_refuse_a_taken_email_or_a_weak_password(): void
    {
        $users = new InMemoryUsers(Users::superAdmin());
        try {
            $this->createSuperAdmin($users, new RecordingLock(), new FixedActor(Actor::cli()))(new CreateSuperAdmin(Users::OTHER_ID, 'root@example.com', self::PASSWORD));
            self::fail('Email taken.');
        } catch (EmailAlreadyRegistered) {
        }

        $lock = new RecordingLock();
        $this->expectException(PasswordRejected::class);
        try {
            $this->createSuperAdmin(new InMemoryUsers(), $lock, new FixedActor(Actor::cli()))(new CreateSuperAdmin(Users::ID, 'root@example.com', 'short'));
        } finally {
            self::assertSame([], $lock->keys, 'Checked before locking.');
        }
    }

    #[Test]
    public function it_should_change_the_holder_password(): void
    {
        $user = Users::member();
        $users = new InMemoryUsers($user);

        new ChangePasswordHandler($this->access($users), new PrefixPasswordHasher())(new ChangePassword(Users::ID, self::PASSWORD));

        self::assertSame('hashed:'.self::PASSWORD, $user->credentials()->passwordHash());
        self::assertSame([Users::ID], $users->locked);
    }

    #[Test]
    public function it_should_change_the_own_password_only_and_apply_the_policy(): void
    {
        $users = new InMemoryUsers(Users::member());
        try {
            new ChangePasswordHandler($this->access($users, FixedActor::user(Users::OTHER_ID)), new PrefixPasswordHasher())(new ChangePassword(Users::ID, self::PASSWORD));
            self::fail('Not the holder.');
        } catch (PermissionDenied) {
            self::assertSame([], $users->locked);
        }

        $this->expectException(PasswordRejected::class);
        new ChangePasswordHandler($this->access($users), new PrefixPasswordHasher())(new ChangePassword(Users::ID, 'jane@example.com and more'));
    }

    #[Test]
    public function it_should_issue_a_reset_link_and_invalidate_the_previous_ones(): void
    {
        $users = new InMemoryUsers(Users::member());
        $requests = new InMemoryResetRequests();
        $clock = new MockClock('2026-11-09 08:00:00');
        $previous = PasswordResetRequest::issue(Uuid::v7(), UserId::fromString(Users::ID), 'old', 'h:old', $clock->now());
        $requests->add($previous);
        $throttle = new ScriptedThrottle();
        $requestId = Uuid::v7()->toRfc4122();

        $this->requestReset($users, $requests, $throttle, $clock)(new RequestPasswordReset($requestId, 'Jane@example.com'));

        self::assertFalse($previous->isUsableAt($clock->now()));
        $issued = $requests->find(Uuid::fromString($requestId));
        self::assertNotNull($issued);
        self::assertTrue($issued->isUsableAt($clock->now()));
        self::assertEquals([new PasswordResetRequested($requestId, Users::ID, 'url-token')], $issued->releaseEvents());
        self::assertSame([
            'consume password_reset_email email:jane@example.com',
            'consume password_reset_ip ip:203.0.113.7',
        ], $throttle->calls);
    }

    #[Test]
    public function it_should_do_nothing_for_an_unknown_or_disabled_account(): void
    {
        $disabled = Users::member(Users::OTHER_ID, 'john@example.com');
        $disabled->deactivate(new \DateTimeImmutable());
        $requests = new InMemoryResetRequests();
        $handler = $this->requestReset(new InMemoryUsers($disabled), $requests, new ScriptedThrottle());

        $handler(new RequestPasswordReset(Uuid::v7()->toRfc4122(), 'nobody@example.com'));
        $handler(new RequestPasswordReset(Uuid::v7()->toRfc4122(), 'john@example.com'));

        self::assertSame([], $requests->requests);
    }

    #[Test]
    public function it_should_skip_the_ip_limiter_outside_a_request(): void
    {
        $throttle = new ScriptedThrottle();

        $this->requestReset(new InMemoryUsers(), new InMemoryResetRequests(), $throttle, request: new FixedRequest(null))(new RequestPasswordReset(Uuid::v7()->toRfc4122(), 'jane@example.com'));

        self::assertSame(['consume password_reset_email email:jane@example.com'], $throttle->calls);
    }

    #[Test]
    public function it_should_throttle_by_email_then_by_ip(): void
    {
        foreach ([ThrottleName::PasswordResetEmail, ThrottleName::PasswordResetIp] as $name) {
            $requests = new InMemoryResetRequests();
            $throttle = new ScriptedThrottle()->refuse($name);
            try {
                $this->requestReset(new InMemoryUsers(Users::member()), $requests, $throttle)(new RequestPasswordReset(Uuid::v7()->toRfc4122(), 'jane@example.com'));
                self::fail($name->value.' should refuse.');
            } catch (Throttled $throttled) {
                self::assertSame($throttle->retryAfter, $throttled->retryAfter);
                self::assertSame([], $requests->requests);
            }
        }
    }

    #[Test]
    public function it_should_reset_the_password_with_a_usable_link(): void
    {
        $user = Users::member();
        $requests = new InMemoryResetRequests();
        $clock = new MockClock('2026-11-09 08:00:00');
        $request = PasswordResetRequest::issue(Uuid::v7(), $user->id(), 't', 'h:t', $clock->now());
        $requests->add($request);
        $users = new InMemoryUsers($user);

        new ResetPasswordHandler($requests, $users, new PrefixPasswordHasher(), $clock)(new ResetPassword($request->id()->toRfc4122(), self::PASSWORD));

        self::assertSame('hashed:'.self::PASSWORD, $user->credentials()->passwordHash());
        self::assertFalse($request->isUsableAt($clock->now()));
        self::assertSame([Users::ID], $users->locked);
    }

    #[Test]
    public function it_should_refuse_an_unknown_link_or_a_disabled_account(): void
    {
        $clock = new MockClock('2026-11-09 08:00:00');
        $user = Users::member();
        $user->deactivate($clock->now());
        $requests = new InMemoryResetRequests();
        $request = PasswordResetRequest::issue(Uuid::v7(), $user->id(), 't', 'h:t', $clock->now());
        $requests->add($request);
        $handler = new ResetPasswordHandler($requests, new InMemoryUsers($user), new PrefixPasswordHasher(), $clock);

        foreach ([Uuid::v7()->toRfc4122(), $request->id()->toRfc4122()] as $id) {
            try {
                $handler(new ResetPassword($id, self::PASSWORD));
                self::fail('Refused.');
            } catch (PasswordResetLinkInvalid) {
            }
        }

        self::assertTrue($request->isUsableAt($clock->now()), 'Not consumed.');
        $orphan = PasswordResetRequest::issue(Uuid::v7(), UserId::fromString(Users::OTHER_ID), 't', 'h:t2', $clock->now());
        $requests->add($orphan);
        $this->expectException(PasswordResetLinkInvalid::class);
        $handler(new ResetPassword($orphan->id()->toRfc4122(), self::PASSWORD));
    }

    #[Test]
    public function it_should_apply_the_policy_on_reset(): void
    {
        $clock = new MockClock('2026-11-09 08:00:00');
        $user = Users::member();
        $requests = new InMemoryResetRequests();
        $request = PasswordResetRequest::issue(Uuid::v7(), $user->id(), 't', 'h:t', $clock->now());
        $requests->add($request);

        $this->expectException(PasswordRejected::class);
        new ResetPasswordHandler($requests, new InMemoryUsers($user), new PrefixPasswordHasher(), $clock)(new ResetPassword($request->id()->toRfc4122(), 'short'));
    }

    private function createSuperAdmin(InMemoryUsers $users, RecordingLock $lock, FixedActor $actor, ?MockClock $clock = null): CreateSuperAdminHandler
    {
        return new CreateSuperAdminHandler($users, new PrefixPasswordHasher(), $lock, $actor, $clock ?? new MockClock());
    }

    private function requestReset(InMemoryUsers $users, InMemoryResetRequests $requests, ScriptedThrottle $throttle, ?MockClock $clock = null, ?FixedRequest $request = null): RequestPasswordResetHandler
    {
        return new RequestPasswordResetHandler($users, $requests, new FixedTokens(), new PrefixTokenHasher(), $throttle, $request ?? new FixedRequest(), $clock ?? new MockClock());
    }

    private function access(InMemoryUsers $users, ?FixedActor $actor = null): AccountAccess
    {
        return new AccountAccess($actor ?? FixedActor::user(), new Grants(), $users);
    }
}
