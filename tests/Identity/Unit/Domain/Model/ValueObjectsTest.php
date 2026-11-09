<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Domain\Model;

use Identity\Domain\Model\AccountStatus;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\Credentials;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\InvalidEmailAddress;
use Identity\Domain\Model\InvalidSecondFactorTransition;
use Identity\Domain\Model\PasswordPolicy;
use Identity\Domain\Model\PasswordRejected;
use Identity\Domain\Model\PasswordResetLinkInvalid;
use Identity\Domain\Model\Privileges;
use Identity\Domain\Model\SelfManagementForbidden;
use Identity\Domain\Model\SessionVersions;
use Identity\Domain\Model\SuperAdminRevocationForbidden;
use Identity\Domain\Model\TotpSecretBinding;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Authorization\Permission;
use Shared\Domain\BusinessRuleViolated;
use Symfony\Component\Uid\Uuid;

final class ValueObjectsTest extends TestCase
{
    #[Test]
    public function it_should_normalize_an_email_address(): void
    {
        $email = EmailAddress::of('  Jane.Doe@Example.COM ');

        self::assertSame('jane.doe@example.com', $email->value());
        self::assertTrue($email->equals(EmailAddress::of('jane.doe@example.com')));
        self::assertFalse($email->equals(EmailAddress::of('john@example.com')));
    }

    #[Test]
    public function it_should_accept_an_email_of_254_characters_exactly(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 61);

        self::assertSame($email, EmailAddress::of($email)->value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEmails(): iterable
    {
        yield 'no at sign' => ['jane.example.com'];
        yield 'empty' => ['  '];
        yield '255 characters' => [str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 62)];
    }

    #[Test]
    #[DataProvider('invalidEmails')]
    public function it_should_refuse_an_invalid_email(string $raw): void
    {
        $this->expectException(InvalidEmailAddress::class);

        EmailAddress::of($raw);
    }

    #[Test]
    public function it_should_hold_a_user_id(): void
    {
        $uuid = Uuid::v7();
        $id = UserId::of($uuid);

        self::assertSame($uuid->toRfc4122(), $id->toString());
        self::assertTrue($id->equals(UserId::fromString($uuid->toRfc4122())));
        self::assertFalse($id->equals(UserId::of(Uuid::v7())));
        self::assertSame('identity.totp_secret:'.$uuid->toRfc4122(), TotpSecretBinding::for($id));
    }

    #[Test]
    public function it_should_apply_the_password_policy(): void
    {
        $email = EmailAddress::of('jane@example.com');
        PasswordPolicy::check(str_repeat('x', 12), $email);
        PasswordPolicy::check(str_repeat('x', 4096), $email);
        PasswordPolicy::check('éééééééééééé', $email);

        foreach ([str_repeat('x', 11), str_repeat('x', 4097), 'my JANE@example.com pass', 'jane@example.com'] as $password) {
            try {
                PasswordPolicy::check($password, $email);
                self::fail(\sprintf('"%s" should be refused.', $password));
            } catch (PasswordRejected $rejected) {
                self::assertSame('password_policy', $rejected->ruleKey());
            }
        }
    }

    #[Test]
    public function it_should_model_credentials(): void
    {
        self::assertFalse(Credentials::none()->hasPassword());
        self::assertTrue(Credentials::password('hash')->hasPassword());
        self::assertSame('hash', Credentials::password('hash')->passwordHash());

        $this->expectException(\LogicException::class);
        Credentials::none()->passwordHash();
    }

    #[Test]
    public function it_should_bump_both_session_versions(): void
    {
        $initial = SessionVersions::initial();
        $revoked = $initial->revokeAll();

        self::assertSame([1, 1], [$initial->authVersion(), $initial->trustedDeviceVersion()]);
        self::assertSame([2, 2], [$revoked->authVersion(), $revoked->trustedDeviceVersion()]);
    }

    #[Test]
    public function it_should_model_the_account_status(): void
    {
        $since = new \DateTimeImmutable('2026-11-09');

        self::assertTrue(AccountStatus::active()->isActive());
        self::assertFalse(AccountStatus::disabled($since)->isActive());
        self::assertSame($since, AccountStatus::disabled($since)->disabledSince());

        $this->expectException(\LogicException::class);
        AccountStatus::active()->disabledSince();
    }

    #[Test]
    public function it_should_grant_instance_permissions_only(): void
    {
        $none = Privileges::none();
        $login = $none->granting(Permission::AccountLogin);

        self::assertFalse($none->has(Permission::AccountLogin));
        self::assertTrue($login->has(Permission::AccountLogin));
        self::assertSame($login, $login->granting(Permission::AccountLogin), 'Granting twice is a no-op.');
        self::assertFalse($login->isSuperAdmin());
        self::assertTrue($login->withSuperAdmin(true)->isSuperAdmin());
        self::assertTrue($login->withSuperAdmin(true)->has(Permission::AccountLogin));
        self::assertTrue($login->withSuperAdmin(true)->granting(Permission::InstanceAuditView)->isSuperAdmin());

        $this->expectException(\InvalidArgumentException::class);
        $none->granting(Permission::TeamEdit);
    }

    #[Test]
    public function it_should_tell_an_accepted_outcome(): void
    {
        self::assertTrue(CodeVerificationOutcome::Accepted->isAccepted());
        self::assertFalse(CodeVerificationOutcome::Rejected->isAccepted());
    }

    /**
     * @return iterable<string, array{BusinessRuleViolated, string}>
     */
    public static function rules(): iterable
    {
        yield 'email' => [new InvalidEmailAddress(), 'invalid_email_address'];
        yield 'password' => [new PasswordRejected(), 'password_policy'];
        yield 'registered' => [new EmailAlreadyRegistered(), 'email_already_registered'];
        yield 'reset link' => [new PasswordResetLinkInvalid(), 'password_reset_link_invalid'];
        yield 'self' => [new SelfManagementForbidden(), 'self_management_forbidden'];
        yield 'super admin' => [new SuperAdminRevocationForbidden(), 'super_admin_revocation_forbidden'];
        yield 'transition' => [new InvalidSecondFactorTransition('verify_totp', 'not_configured'), 'invalid_second_factor_transition'];
    }

    #[Test]
    #[DataProvider('rules')]
    public function it_should_name_each_rule(BusinessRuleViolated $violation, string $ruleKey): void
    {
        self::assertSame($ruleKey, $violation->ruleKey());
    }

    #[Test]
    public function it_should_carry_its_rule_key_once_built(): void
    {
        self::assertSame('email_already_registered', new EmailAlreadyRegistered()->ruleKey());
        self::assertSame('password_reset_link_invalid', new PasswordResetLinkInvalid()->ruleKey());
        self::assertSame('self_management_forbidden', new SelfManagementForbidden()->ruleKey());
        self::assertSame('super_admin_revocation_forbidden', new SuperAdminRevocationForbidden()->ruleKey());
    }

    #[Test]
    public function it_should_refuse_a_disabled_status_without_its_date(): void
    {
        $broken = AccountStatus::disabled(new \DateTimeImmutable());
        new \ReflectionProperty(AccountStatus::class, 'disabledSince')->setValue($broken, null);

        $this->expectException(\LogicException::class);
        $broken->disabledSince();
    }

    #[Test]
    public function it_should_describe_an_invalid_transition(): void
    {
        self::assertSame('Cannot "verify_totp" a second factor in state "not_configured".', new InvalidSecondFactorTransition('verify_totp', 'not_configured')->getMessage());
    }
}
