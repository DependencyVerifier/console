<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Command;

use Identity\Application\Command\Administration\DeactivateUser;
use Identity\Application\Command\Administration\GrantSuperAdmin;
use Identity\Application\Command\Administration\Handler\DeactivateUserHandler;
use Identity\Application\Command\Administration\Handler\GrantSuperAdminHandler;
use Identity\Application\Command\Administration\Handler\ReactivateUserHandler;
use Identity\Application\Command\Administration\Handler\ResetSecondFactorHandler;
use Identity\Application\Command\Administration\Handler\RevokeSuperAdminHandler;
use Identity\Application\Command\Administration\ReactivateUser;
use Identity\Application\Command\Administration\ResetSecondFactor;
use Identity\Application\Command\Administration\RevokeSuperAdmin;
use Identity\Application\Command\SecondFactor\ConfirmTotp;
use Identity\Application\Command\SecondFactor\DisableTotp;
use Identity\Application\Command\SecondFactor\Handler\ConfirmTotpHandler;
use Identity\Application\Command\SecondFactor\Handler\DisableTotpHandler;
use Identity\Application\Command\SecondFactor\Handler\IssueEmailLoginCodeHandler;
use Identity\Application\Command\SecondFactor\Handler\RegenerateBackupCodesHandler;
use Identity\Application\Command\SecondFactor\Handler\StartTotpEnrollmentHandler;
use Identity\Application\Command\SecondFactor\Handler\UseBackupCodeHandler;
use Identity\Application\Command\SecondFactor\Handler\VerifyEmailLoginCodeHandler;
use Identity\Application\Command\SecondFactor\Handler\VerifyTotpHandler;
use Identity\Application\Command\SecondFactor\IssueEmailLoginCode;
use Identity\Application\Command\SecondFactor\RegenerateBackupCodes;
use Identity\Application\Command\SecondFactor\StartTotpEnrollment;
use Identity\Application\Command\SecondFactor\UseBackupCode;
use Identity\Application\Command\SecondFactor\VerifyEmailLoginCode;
use Identity\Application\Command\SecondFactor\VerifyTotp;
use Identity\Application\SecondFactor\BackupCodeCheck;
use Identity\Application\SecondFactor\EmailCodeCheck;
use Identity\Application\SecondFactor\SecondFactorAttempt;
use Identity\Application\SecondFactor\TotpCheck;
use Identity\Application\SecondFactor\TotpEnrollmentCheck;
use Identity\Application\Security\AccountAccess;
use Identity\Application\Security\LastSuperAdminGuard;
use Identity\Domain\Event\EmailLoginCodeIssued;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\SecondFactor\PendingTotp;
use Identity\Domain\Model\SelfManagementForbidden;
use Identity\Domain\Model\SuperAdminRevocationForbidden;
use Identity\Domain\Model\TotpSecretBinding;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Actor;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Security\Throttled;
use Shared\Application\Security\ThrottleName;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedRequest;
use Tests\Identity\Support\FixedSecret;
use Tests\Identity\Support\FixedTokens;
use Tests\Identity\Support\Grants;
use Tests\Identity\Support\ImmediateSection;
use Tests\Identity\Support\InMemoryAttempts;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\LabelSecretBox;
use Tests\Identity\Support\MappedTotpVerifier;
use Tests\Identity\Support\PrefixMatcher;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Identity\Support\RecordingAudit;
use Tests\Identity\Support\RecordingLock;
use Tests\Identity\Support\ScriptedThrottle;
use Tests\Identity\Support\TokenMatcher;
use Tests\Identity\Support\Users;

final class SecondFactorAndAdministrationHandlersTest extends TestCase
{
    #[Test]
    public function it_should_issue_an_email_code_within_the_resend_limit(): void
    {
        $user = Users::member();
        $throttle = new ScriptedThrottle();
        $clock = new MockClock('2026-11-09 08:00:00');
        $users = new InMemoryUsers($user);

        new IssueEmailLoginCodeHandler($this->access($users), $throttle, new FixedTokens(), new PrefixTokenHasher(), $clock)(new IssueEmailLoginCode(Users::ID, Uuid::v7()->toRfc4122()));

        self::assertEquals([new EmailLoginCodeIssued(Users::ID, '424242')], $user->releaseEvents());
        self::assertSame(['consume email_code_resend account:'.Users::ID], $throttle->calls);
        self::assertSame(CodeVerificationOutcome::Accepted, $user->verifyEmailLoginCode('424242', new TokenMatcher(), Uuid::v7(), $clock->now()));
        self::assertSame([Users::ID], $users->locked);
    }

    #[Test]
    public function it_should_refuse_a_resend_beyond_the_limit_or_for_someone_else(): void
    {
        $user = Users::member();
        try {
            new IssueEmailLoginCodeHandler($this->access(new InMemoryUsers($user)), new ScriptedThrottle()->refuse(ThrottleName::EmailCodeResend), new FixedTokens(), new PrefixTokenHasher(), new MockClock())(new IssueEmailLoginCode(Users::ID, Uuid::v7()->toRfc4122()));
            self::fail('Throttled.');
        } catch (Throttled) {
            self::assertSame([], $user->releaseEvents());
        }

        $this->expectException(PermissionDenied::class);
        new IssueEmailLoginCodeHandler($this->access(new InMemoryUsers($user), FixedActor::user(Users::OTHER_ID)), new ScriptedThrottle(), new FixedTokens(), new PrefixTokenHasher(), new MockClock())(new IssueEmailLoginCode(Users::ID, Uuid::v7()->toRfc4122()));
    }

    #[Test]
    public function it_should_start_an_enrolment_with_an_encrypted_bound_secret(): void
    {
        $user = Users::member();

        new StartTotpEnrollmentHandler($this->access(new InMemoryUsers($user)), new FixedSecret(), new LabelSecretBox())(new StartTotpEnrollment(Users::ID));

        $state = $user->secondFactor()->state();
        self::assertInstanceOf(PendingTotp::class, $state);
        self::assertSame('enc['.TotpSecretBinding::for(UserId::fromString(Users::ID)).']:JBSWY3DPEHPK3PXP', $state->encryptedSecret());
    }

    #[Test]
    public function it_should_disable_totp_and_regenerate_backup_codes_for_the_holder(): void
    {
        $user = Users::withTotp(Users::member());
        $users = new InMemoryUsers($user);

        new RegenerateBackupCodesHandler($this->access($users), new PrefixTokenHasher())(new RegenerateBackupCodes(Users::ID, ['ccccc-33333', 'ddddd-44444']));
        self::assertSame(2, $user->remainingBackupCodes());
        self::assertSame(CodeVerificationOutcome::Accepted, $user->useBackupCode('ddddd-44444', new TokenMatcher(), new \DateTimeImmutable()));

        new DisableTotpHandler($this->access($users))(new DisableTotp(Users::ID));
        self::assertFalse($user->secondFactor()->state()->isTotpActive());
        self::assertSame([Users::ID, Users::ID], $users->locked);
    }

    #[Test]
    public function it_should_keep_self_service_to_the_holder(): void
    {
        $users = new InMemoryUsers(Users::withTotp(Users::member()));
        $other = $this->access($users, FixedActor::user(Users::OTHER_ID));
        $gestures = [
            static fn () => new DisableTotpHandler($other)(new DisableTotp(Users::ID)),
            static fn () => new RegenerateBackupCodesHandler($other, new PrefixTokenHasher())(new RegenerateBackupCodes(Users::ID, ['x'])),
            static fn () => new StartTotpEnrollmentHandler($other, new FixedSecret(), new LabelSecretBox())(new StartTotpEnrollment(Users::ID)),
        ];
        foreach ($gestures as $gesture) {
            try {
                $gesture();
                self::fail('Not the holder.');
            } catch (PermissionDenied) {
            }
        }

        self::assertSame([], $users->locked);
    }

    #[Test]
    public function it_should_route_each_verification_through_the_shared_algorithm(): void
    {
        $user = Users::withTotp(Users::member());
        $attempts = new InMemoryAttempts();
        $attempt = new SecondFactorAttempt($this->access(new InMemoryUsers($user)), $attempts, new ScriptedThrottle(), new ImmediateSection(), new FixedRequest(), new RecordingAudit(), new MockClock());

        new VerifyTotpHandler($attempt, new TotpCheck(new MappedTotpVerifier(['111111' => 500])))(new VerifyTotp(Users::ID, '111111', $totp = Uuid::v7()->toRfc4122()));
        new UseBackupCodeHandler($attempt, new BackupCodeCheck(new PrefixMatcher()))(new UseBackupCode(Users::ID, 'aaaaa-11111', $backup = Uuid::v7()->toRfc4122()));

        self::assertSame(CodeVerificationOutcome::Accepted, $attempts->find(Uuid::fromString($totp))?->outcome());
        self::assertSame(CodeVerificationOutcome::Accepted, $attempts->find(Uuid::fromString($backup))?->outcome());

        $member = Users::member();
        $member->issueEmailLoginCode(Uuid::v7(), '424242', 'h:424242', new \DateTimeImmutable());
        $pending = new SecondFactorAttempt($this->access(new InMemoryUsers($member)), $attempts, new ScriptedThrottle(), new ImmediateSection(), new FixedRequest(), new RecordingAudit(), new MockClock());
        new VerifyEmailLoginCodeHandler($pending, new EmailCodeCheck(new TokenMatcher()))(new VerifyEmailLoginCode(Users::ID, '424242', $email = Uuid::v7()->toRfc4122()));
        self::assertSame(CodeVerificationOutcome::Accepted, $attempts->find(Uuid::fromString($email))?->outcome());

        $member->startTotpEnrollment('secret');
        new ConfirmTotpHandler($pending, new TotpEnrollmentCheck(new MappedTotpVerifier(['999999' => 9]), new PrefixTokenHasher()))(new ConfirmTotp(Users::ID, '999999', $confirm = Uuid::v7()->toRfc4122(), ['eeeee-55555']));
        self::assertSame(CodeVerificationOutcome::Accepted, $attempts->find(Uuid::fromString($confirm))?->outcome());
    }

    #[Test]
    public function it_should_administer_accounts_with_the_instance_permission(): void
    {
        $user = Users::withTotp(Users::member(Users::OTHER_ID, 'john@example.com'));
        $users = new InMemoryUsers($user);
        $grants = new Grants([Permission::InstanceUsersManage, Permission::InstanceSuperAdminManage]);
        $access = $this->access($users, null, $grants);
        $clock = new MockClock('2026-11-09 08:00:00');

        new ResetSecondFactorHandler($access)(new ResetSecondFactor(Users::OTHER_ID));
        self::assertFalse($user->secondFactor()->state()->isTotpActive());

        new GrantSuperAdminHandler($access)(new GrantSuperAdmin(Users::OTHER_ID));
        self::assertTrue($user->isSuperAdmin());

        new DeactivateUserHandler($access, $this->guard($users, new InMemoryUsers(Users::superAdmin(), $user)), $clock)(new DeactivateUser(Users::OTHER_ID));
        self::assertFalse($user->status()->isActive());
        self::assertEquals($clock->now(), $user->status()->disabledSince());

        new ReactivateUserHandler($access)(new ReactivateUser(Users::OTHER_ID));
        self::assertTrue($user->status()->isActive());

        new RevokeSuperAdminHandler($access, $this->guard($users, new InMemoryUsers(Users::superAdmin(), $user)))(new RevokeSuperAdmin(Users::OTHER_ID));
        self::assertFalse($user->isSuperAdmin());
        self::assertSame([
            Permission::InstanceUsersManage,
            Permission::InstanceSuperAdminManage,
            Permission::InstanceUsersManage,
            Permission::InstanceUsersManage,
            Permission::InstanceSuperAdminManage,
        ], $grants->asked);
    }

    #[Test]
    public function it_should_deny_administration_without_the_permission(): void
    {
        $users = new InMemoryUsers(Users::member(Users::OTHER_ID, 'john@example.com'));
        $access = $this->access($users, null, new Grants([Permission::InstanceUsersManage]));
        $none = $this->access($users);
        $gestures = [
            static fn () => new ResetSecondFactorHandler($none)(new ResetSecondFactor(Users::OTHER_ID)),
            static fn () => new ReactivateUserHandler($none)(new ReactivateUser(Users::OTHER_ID)),
            fn () => new DeactivateUserHandler($none, $this->guard($users), new MockClock())(new DeactivateUser(Users::OTHER_ID)),
            static fn () => new GrantSuperAdminHandler($access)(new GrantSuperAdmin(Users::OTHER_ID)),
            fn () => new RevokeSuperAdminHandler($access, $this->guard($users))(new RevokeSuperAdmin(Users::OTHER_ID)),
        ];
        foreach ($gestures as $gesture) {
            try {
                $gesture();
                self::fail('Permission required.');
            } catch (PermissionDenied) {
            }
        }

        self::assertSame([], $users->locked);
    }

    #[Test]
    public function it_should_answer_not_found_for_an_unknown_account(): void
    {
        $this->expectException(ResourceNotFound::class);

        new ResetSecondFactorHandler($this->access(new InMemoryUsers(), null, new Grants([Permission::InstanceUsersManage])))(new ResetSecondFactor(Users::OTHER_ID));
    }

    #[Test]
    public function it_should_keep_the_last_active_super_admin(): void
    {
        $last = Users::superAdmin(Users::OTHER_ID, 'root@example.com');
        $users = new InMemoryUsers($last);
        $lock = new RecordingLock();
        $access = $this->access($users, null, new Grants([Permission::InstanceUsersManage, Permission::InstanceSuperAdminManage]));

        foreach ([
            static fn () => new RevokeSuperAdminHandler($access, new LastSuperAdminGuard($lock, $users))(new RevokeSuperAdmin(Users::OTHER_ID)),
            static fn () => new DeactivateUserHandler($access, new LastSuperAdminGuard($lock, $users), new MockClock())(new DeactivateUser(Users::OTHER_ID)),
        ] as $gesture) {
            try {
                $gesture();
                self::fail('Last super admin.');
            } catch (SuperAdminRevocationForbidden) {
            }
        }

        self::assertTrue($last->isSuperAdmin());
        self::assertTrue($last->status()->isActive());
        self::assertSame([LastSuperAdminGuard::LOCK, LastSuperAdminGuard::LOCK], $lock->keys);
    }

    #[Test]
    public function it_should_count_active_super_admins_only(): void
    {
        $target = Users::superAdmin(Users::OTHER_ID, 'root@example.com');
        $disabled = Users::superAdmin(Users::ID, 'old@example.com');
        $disabled->deactivate(new \DateTimeImmutable());
        $users = new InMemoryUsers($target, $disabled);

        $this->expectException(SuperAdminRevocationForbidden::class);
        new LastSuperAdminGuard(new RecordingLock(), $users)->assertNotLast($target);
    }

    #[Test]
    public function it_should_let_a_disabled_or_plain_account_go_without_counting(): void
    {
        $guard = new LastSuperAdminGuard(new RecordingLock(), new InMemoryUsers());
        $disabled = Users::superAdmin(Users::OTHER_ID);
        $disabled->deactivate(new \DateTimeImmutable());

        $guard->assertNotLast($disabled);
        $guard->assertNotLast(Users::member(Users::OTHER_ID));
        $this->addToAssertionCount(2);
    }

    #[Test]
    public function it_should_never_revoke_or_deactivate_oneself(): void
    {
        $self = Users::superAdmin(Users::ID);
        $users = new InMemoryUsers($self, Users::superAdmin(Users::OTHER_ID, 'other@example.com'));
        $access = $this->access($users, null, new Grants([Permission::InstanceUsersManage, Permission::InstanceSuperAdminManage]));
        $lock = new RecordingLock();

        try {
            new RevokeSuperAdminHandler($access, new LastSuperAdminGuard($lock, $users))(new RevokeSuperAdmin(Users::ID));
            self::fail('Oneself.');
        } catch (SuperAdminRevocationForbidden) {
        }

        try {
            new DeactivateUserHandler($access, new LastSuperAdminGuard($lock, $users), new MockClock())(new DeactivateUser(Users::ID));
            self::fail('Oneself.');
        } catch (SelfManagementForbidden) {
        }

        self::assertTrue($self->isSuperAdmin());
        self::assertSame([], $lock->keys);
    }

    #[Test]
    public function it_should_tell_who_the_actor_is(): void
    {
        $access = $this->access(new InMemoryUsers());

        self::assertTrue($access->isActor(UserId::fromString(Users::ID)));
        self::assertFalse($access->isActor(UserId::fromString(Users::OTHER_ID)));
        self::assertFalse(new AccountAccess(new FixedActor(Actor::cli()), new Grants(), new InMemoryUsers())->isActor(UserId::fromString(Users::ID)));

        $this->expectException(PermissionDenied::class);
        new AccountAccess(new FixedActor(Actor::cli()), new Grants(), new InMemoryUsers())->assertSelf(UserId::fromString(Users::ID));
    }

    private function access(InMemoryUsers $users, ?FixedActor $actor = null, ?Grants $grants = null): AccountAccess
    {
        return new AccountAccess($actor ?? FixedActor::user(), $grants ?? new Grants(), $users);
    }

    private function guard(InMemoryUsers $users, ?InMemoryUsers $counted = null): LastSuperAdminGuard
    {
        return new LastSuperAdminGuard(new RecordingLock(), $counted ?? $users);
    }
}
