<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Domain\Model;

use Identity\Domain\Event\BackupCodesRegenerated;
use Identity\Domain\Event\EmailLoginCodeIssued;
use Identity\Domain\Event\PasswordChanged;
use Identity\Domain\Event\SecondFactorReset;
use Identity\Domain\Event\SecondFactorThrottled;
use Identity\Domain\Event\SuperAdminGranted;
use Identity\Domain\Event\SuperAdminRevoked;
use Identity\Domain\Event\TotpDisabled;
use Identity\Domain\Event\TotpEnabled;
use Identity\Domain\Event\UserDeactivated;
use Identity\Domain\Event\UserReactivated;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\Credentials;
use Identity\Domain\Model\InvalidSecondFactorTransition;
use Identity\Domain\Model\Privileges;
use Identity\Domain\Model\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\MappedTotpVerifier;
use Tests\Identity\Support\PrefixMatcher;
use Tests\Identity\Support\Users;

final class UserTest extends TestCase
{
    private const string NOW = '2026-11-09 08:00:00';

    #[Test]
    public function it_should_create_a_super_admin_and_an_invited_account(): void
    {
        $admin = Users::superAdmin();
        $member = Users::member(Users::OTHER_ID, 'jane@example.com');

        self::assertSame(Users::ID, $admin->id()->toString());
        self::assertSame('root@example.com', $admin->email()->value());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $admin->createdAt());
        self::assertTrue($admin->isSuperAdmin());
        self::assertFalse($member->isSuperAdmin());
        foreach ([$admin, $member] as $user) {
            self::assertTrue($user->canLogIn());
            self::assertTrue($user->privileges()->has(Permission::AccountLogin));
            self::assertSame('password-hash', $user->credentials()->passwordHash());
            self::assertSame('not_configured', $user->secondFactor()->state()->name());
            self::assertSame(1, $user->sessionVersions()->authVersion());
            self::assertTrue($user->status()->isActive());
            self::assertSame(0, $user->remainingBackupCodes());
            self::assertSame([], $user->releaseEvents());
        }
    }

    #[Test]
    public function it_should_change_the_password_and_revoke_every_session(): void
    {
        $user = Users::member();
        $user->changePassword('new-hash');

        self::assertSame('new-hash', $user->credentials()->passwordHash());
        $this->assertRevoked($user);
        self::assertEquals([new PasswordChanged(Users::ID)], $user->releaseEvents());
    }

    #[Test]
    public function it_should_issue_one_active_email_code_at_a_time(): void
    {
        $user = Users::member();
        $now = new \DateTimeImmutable(self::NOW);
        $user->issueEmailLoginCode(Uuid::v7(), '111111', PrefixMatcher::hash('111111'), $now);
        $user->issueEmailLoginCode(Uuid::v7(), '222222', PrefixMatcher::hash('222222'), $now);

        self::assertEquals([new EmailLoginCodeIssued(Users::ID, '111111'), new EmailLoginCodeIssued(Users::ID, '222222')], $user->releaseEvents());
        self::assertSame(CodeVerificationOutcome::Rejected, $user->verifyEmailLoginCode('111111', new PrefixMatcher(), Uuid::v7(), $now), 'The first code was invalidated.');
        self::assertSame(CodeVerificationOutcome::Accepted, $user->verifyEmailLoginCode('222222', new PrefixMatcher(), Uuid::v7(), $now));
        self::assertSame(CodeVerificationOutcome::Expired, $user->verifyEmailLoginCode('222222', new PrefixMatcher(), Uuid::v7(), $now), 'No active code left.');
        self::assertSame(1, $user->sessionVersions()->authVersion(), 'A login code revokes nothing.');
    }

    #[Test]
    public function it_should_expire_when_no_code_was_issued(): void
    {
        self::assertSame(CodeVerificationOutcome::Expired, Users::member()->verifyEmailLoginCode('123456', new PrefixMatcher(), Uuid::v7(), new \DateTimeImmutable()));
    }

    #[Test]
    public function it_should_refuse_email_codes_once_totp_is_active(): void
    {
        $user = Users::withTotp(Users::member());

        try {
            $user->issueEmailLoginCode(Uuid::v7(), '111111', 'h', new \DateTimeImmutable());
            self::fail('No email code with an active TOTP.');
        } catch (InvalidSecondFactorTransition $refused) {
            self::assertStringContainsString('"issue_email_code"', $refused->getMessage());
        }

        $this->expectException(InvalidSecondFactorTransition::class);
        $this->expectExceptionMessageIsOrContains('"verify_email_code"');
        $user->verifyEmailLoginCode('111111', new PrefixMatcher(), Uuid::v7(), new \DateTimeImmutable());
    }

    #[Test]
    public function it_should_keep_email_codes_during_a_pending_enrolment(): void
    {
        $user = Users::member();
        $user->startTotpEnrollment('secret');
        $user->issueEmailLoginCode(Uuid::v7(), '111111', PrefixMatcher::hash('111111'), new \DateTimeImmutable(self::NOW));

        self::assertSame(CodeVerificationOutcome::Accepted, $user->verifyEmailLoginCode('111111', new PrefixMatcher(), Uuid::v7(), new \DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function it_should_confirm_totp_with_its_backup_codes_on_acceptance_only(): void
    {
        $user = Users::member();
        $user->startTotpEnrollment('secret');
        $verifier = new MappedTotpVerifier(['123456' => 50]);
        $now = new \DateTimeImmutable(self::NOW);

        self::assertSame(CodeVerificationOutcome::Rejected, $user->confirmTotp('000000', $verifier, ['h:a'], $now));
        self::assertSame('pending_totp', $user->secondFactor()->state()->name());
        self::assertSame(0, $user->remainingBackupCodes());
        self::assertSame(1, $user->sessionVersions()->authVersion());
        self::assertSame([], $user->releaseEvents());

        self::assertSame(CodeVerificationOutcome::Accepted, $user->confirmTotp('123456', $verifier, [PrefixMatcher::hash('a'), PrefixMatcher::hash('b')], $now));
        self::assertSame('active_totp', $user->secondFactor()->state()->name());
        self::assertSame(2, $user->remainingBackupCodes());
        $this->assertRevoked($user);
        self::assertEquals([new TotpEnabled(Users::ID)], $user->releaseEvents());
        self::assertSame(['secret', Users::ID, '123456'], $verifier->asked[1]);
    }

    #[Test]
    public function it_should_verify_totp_and_refuse_a_replay(): void
    {
        $user = Users::withTotp(Users::member());
        $verifier = new MappedTotpVerifier(['111111' => 101, '000000' => 100]);
        $now = new \DateTimeImmutable(self::NOW);

        self::assertSame(CodeVerificationOutcome::Rejected, $user->verifyTotp('000000', $verifier, $now), 'Step of the confirmation.');
        self::assertSame(CodeVerificationOutcome::Accepted, $user->verifyTotp('111111', $verifier, $now));
        self::assertSame(CodeVerificationOutcome::Rejected, $user->verifyTotp('111111', $verifier, $now), 'Replay.');
        self::assertSame([], $user->releaseEvents());
    }

    #[Test]
    public function it_should_use_each_backup_code_once(): void
    {
        $user = Users::withTotp(Users::member());
        $now = new \DateTimeImmutable(self::NOW);

        self::assertSame(CodeVerificationOutcome::Accepted, $user->useBackupCode('bbbbb-22222', new PrefixMatcher(), $now));
        self::assertSame(1, $user->remainingBackupCodes());
        $reused = $user->useBackupCode('bbbbb-22222', new PrefixMatcher(), $now);
        $unknown = $user->useBackupCode('zzzzz-99999', new PrefixMatcher(), $now);
        $last = $user->useBackupCode('aaaaa-11111', new PrefixMatcher(), $now);
        self::assertSame([CodeVerificationOutcome::Rejected, CodeVerificationOutcome::Rejected, CodeVerificationOutcome::Accepted], [$reused, $unknown, $last]);
        self::assertSame(0, $user->remainingBackupCodes());
    }

    #[Test]
    public function it_should_refuse_backup_codes_without_an_active_totp(): void
    {
        $user = Users::member();

        try {
            $user->useBackupCode('aaaaa-11111', new PrefixMatcher(), new \DateTimeImmutable());
            self::fail('No backup code without TOTP.');
        } catch (InvalidSecondFactorTransition $refused) {
            self::assertStringContainsString('"use_backup_code"', $refused->getMessage());
        }

        $this->expectException(InvalidSecondFactorTransition::class);
        $this->expectExceptionMessageIsOrContains('"regenerate_backup_codes"');
        $user->regenerateBackupCodes(['h']);
    }

    #[Test]
    public function it_should_regenerate_the_whole_set_of_backup_codes(): void
    {
        $user = Users::withTotp(Users::member());
        $before = $user->sessionVersions()->authVersion();
        $user->regenerateBackupCodes([PrefixMatcher::hash('ccccc-33333')]);

        self::assertSame(1, $user->remainingBackupCodes());
        self::assertSame(CodeVerificationOutcome::Rejected, $user->useBackupCode('aaaaa-11111', new PrefixMatcher(), new \DateTimeImmutable()));
        self::assertSame(CodeVerificationOutcome::Accepted, $user->useBackupCode('ccccc-33333', new PrefixMatcher(), new \DateTimeImmutable()));
        self::assertSame($before + 1, $user->sessionVersions()->authVersion());
        self::assertSame($before + 1, $user->sessionVersions()->trustedDeviceVersion());
        self::assertEquals([new BackupCodesRegenerated(Users::ID)], $user->releaseEvents());
    }

    #[Test]
    public function it_should_disable_an_active_totp(): void
    {
        $user = Users::withTotp(Users::member());
        $before = $user->sessionVersions()->authVersion();
        $user->disableTotp();

        self::assertSame('not_configured', $user->secondFactor()->state()->name());
        self::assertSame(0, $user->remainingBackupCodes());
        self::assertSame($before + 1, $user->sessionVersions()->authVersion());
        self::assertEquals([new TotpDisabled(Users::ID)], $user->releaseEvents());
    }

    #[Test]
    public function it_should_abandon_a_pending_enrolment_silently(): void
    {
        $user = Users::member();
        $user->startTotpEnrollment('secret');
        $user->disableTotp();

        self::assertSame('not_configured', $user->secondFactor()->state()->name());
        self::assertSame(1, $user->sessionVersions()->authVersion());
        self::assertSame([], $user->releaseEvents());
    }

    #[Test]
    public function it_should_reset_the_second_factor_in_any_state(): void
    {
        $user = Users::withTotp(Users::member());
        $before = $user->sessionVersions()->authVersion();
        $user->resetSecondFactor();

        self::assertSame('not_configured', $user->secondFactor()->state()->name());
        self::assertSame(0, $user->remainingBackupCodes());
        self::assertSame($before + 1, $user->sessionVersions()->authVersion());
        self::assertEquals([new SecondFactorReset(Users::ID)], $user->releaseEvents());

        $fresh = Users::member();
        $fresh->resetSecondFactor();
        self::assertEquals([new SecondFactorReset(Users::ID)], $fresh->releaseEvents());
    }

    #[Test]
    public function it_should_note_a_throttled_second_factor_without_changing_state(): void
    {
        $user = Users::member();
        $until = new \DateTimeImmutable('2026-11-10');
        $user->noteSecondFactorThrottled($until);

        self::assertEquals([new SecondFactorThrottled(Users::ID, $until)], $user->releaseEvents());
        self::assertSame(1, $user->sessionVersions()->authVersion());
    }

    #[Test]
    public function it_should_deactivate_and_reactivate_once(): void
    {
        $user = Users::member();
        $now = new \DateTimeImmutable(self::NOW);
        $user->deactivate($now);
        $user->deactivate($now->modify('+1 day'));

        self::assertFalse($user->canLogIn());
        self::assertSame($now, $user->status()->disabledSince());
        $this->assertRevoked($user);
        self::assertEquals([new UserDeactivated(Users::ID)], $user->releaseEvents());

        $user->reactivate();
        $user->reactivate();
        self::assertTrue($user->canLogIn());
        self::assertSame(2, $user->sessionVersions()->authVersion(), 'Reactivation revokes nothing more.');
        self::assertEquals([new UserReactivated(Users::ID)], $user->releaseEvents());
    }

    #[Test]
    public function it_should_grant_and_revoke_super_admin_once(): void
    {
        $user = Users::member();
        $user->grantSuperAdmin();
        $user->grantSuperAdmin();

        self::assertTrue($user->isSuperAdmin());
        self::assertSame(1, $user->sessionVersions()->authVersion(), 'Granting keeps the sessions.');
        self::assertEquals([new SuperAdminGranted(Users::ID)], $user->releaseEvents());

        $user->revokeSuperAdmin();
        $user->revokeSuperAdmin();
        self::assertFalse($user->isSuperAdmin());
        $this->assertRevoked($user);
        self::assertEquals([new SuperAdminRevoked(Users::ID)], $user->releaseEvents());
    }

    #[Test]
    public function it_should_not_log_in_without_the_login_permission(): void
    {
        $user = Users::member();
        new \ReflectionProperty(User::class, 'privileges')->setValue($user, Privileges::none());

        self::assertFalse($user->canLogIn());
    }

    #[Test]
    public function it_should_not_log_in_without_a_password(): void
    {
        $user = Users::member();
        new \ReflectionProperty(User::class, 'credentials')->setValue($user, Credentials::none());

        self::assertFalse($user->canLogIn());
    }

    private function assertRevoked(User $user): void
    {
        self::assertSame(2, $user->sessionVersions()->authVersion());
        self::assertSame(2, $user->sessionVersions()->trustedDeviceVersion());
    }
}
