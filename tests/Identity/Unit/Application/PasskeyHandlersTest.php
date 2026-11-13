<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application;

use Identity\Application\Command\Passkey\AuthenticateWithPasskey;
use Identity\Application\Command\Passkey\Handler\AuthenticateWithPasskeyHandler;
use Identity\Application\Command\Passkey\Handler\RegisterPasskeyHandler;
use Identity\Application\Command\Passkey\Handler\RevokePasskeyHandler;
use Identity\Application\Command\Passkey\Handler\VerifyPasskeyHandler;
use Identity\Application\Command\Passkey\RegisterPasskey;
use Identity\Application\Command\Passkey\RevokePasskey;
use Identity\Application\Command\Passkey\VerifyPasskey;
use Identity\Application\Command\SecondFactor\VerifyTotp;
use Identity\Application\Port\PasskeyOptions;
use Identity\Application\Query\Passkey\GetPasskeyCreationOptions;
use Identity\Application\Query\Passkey\GetPasskeyRequestOptions;
use Identity\Application\Query\Passkey\GetPasskeySignIn;
use Identity\Application\Query\Passkey\Handler\GetPasskeyCreationOptionsHandler;
use Identity\Application\Query\Passkey\Handler\GetPasskeyRequestOptionsHandler;
use Identity\Application\Query\Passkey\Handler\GetPasskeySignInHandler;
use Identity\Application\SecondFactor\PasskeyCheck;
use Identity\Application\SecondFactor\SecondFactorAttempt;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\CodeKind;
use Identity\Domain\Model\CodeVerificationAttempt;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\NewPasskey;
use Identity\Domain\Model\PasskeyRejected;
use Identity\Domain\Model\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\OutcomeKind;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Security\ThrottleName;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedRequest;
use Tests\Identity\Support\Grants;
use Tests\Identity\Support\ImmediateSection;
use Tests\Identity\Support\InMemoryAttempts;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\RecordingAudit;
use Tests\Identity\Support\ScriptedCeremony;
use Tests\Identity\Support\ScriptedThrottle;
use Tests\Identity\Support\Users;

final class PasskeyHandlersTest extends TestCase
{
    private User $user;
    private InMemoryUsers $users;
    private ScriptedCeremony $ceremony;
    private InMemoryAttempts $attempts;
    private ScriptedThrottle $throttle;
    private RecordingAudit $audit;
    private MockClock $clock;
    private Uuid $passkeyId;

    protected function setUp(): void
    {
        $this->user = Users::member();
        $this->passkeyId = Uuid::v7();
        $this->user->registerPasskey($this->passkeyId, new NewPasskey('cred-1', 'cose', 3, [], '00000000-0000-0000-0000-000000000000'), 'Phone', new \DateTimeImmutable('2026-11-15'));
        $this->users = new InMemoryUsers($this->user, Users::member(Users::OTHER_ID, 'john@example.com'));
        $this->ceremony = new ScriptedCeremony();
        $this->attempts = new InMemoryAttempts();
        $this->throttle = new ScriptedThrottle();
        $this->audit = new RecordingAudit();
        $this->clock = new MockClock('2026-11-15 08:00:00');
    }

    #[Test]
    public function it_should_register_the_verified_passkey_of_its_holder(): void
    {
        new RegisterPasskeyHandler($this->access(), $this->ceremony, $this->clock)(new RegisterPasskey(Users::ID, $id = Uuid::v7()->toRfc4122(), 'Laptop', 'cred-2', '{"o":1}'));

        self::assertSame(['register cred-2 {"o":1}'], $this->ceremony->calls);
        self::assertSame([Users::ID], $this->users->locked);
        self::assertTrue($this->user->hasPasskey(Uuid::fromString($id)));
        self::assertEquals($this->clock->now(), $this->user->passkeys()[1]->createdAt());

        $this->expectException(PasskeyRejected::class);
        new RegisterPasskeyHandler($this->access(), $this->ceremony, $this->clock)(new RegisterPasskey(Users::ID, Uuid::v7()->toRfc4122(), 'Other', 'bad', '{}'));
    }

    #[Test]
    public function it_should_register_for_its_holder_only(): void
    {
        $this->expectException(PermissionDenied::class);
        new RegisterPasskeyHandler($this->access(FixedActor::user(Users::OTHER_ID)), $this->ceremony, $this->clock)(new RegisterPasskey(Users::ID, Uuid::v7()->toRfc4122(), 'Laptop', 'cred-2', '{}'));
    }

    #[Test]
    public function it_should_revoke_for_its_holder_or_a_users_manager(): void
    {
        new RevokePasskeyHandler($this->access())(new RevokePasskey(Users::ID, $this->passkeyId->toRfc4122()));
        self::assertSame([], $this->user->passkeys());

        $this->user->registerPasskey($again = Uuid::v7(), new NewPasskey('cred-3', 'cose', 0, [], '00000000-0000-0000-0000-000000000000'), 'Key', new \DateTimeImmutable());
        new RevokePasskeyHandler($this->access(FixedActor::user(Users::OTHER_ID), new Grants([Permission::InstanceUsersManage])))(new RevokePasskey(Users::ID, $again->toRfc4122()));
        self::assertSame([], $this->user->passkeys(), 'A super admin.');

        try {
            new RevokePasskeyHandler($this->access())(new RevokePasskey(Users::ID, Uuid::v7()->toRfc4122()));
            self::fail('An unknown passkey.');
        } catch (ResourceNotFound) {
        }

        $this->expectException(PermissionDenied::class);
        new RevokePasskeyHandler($this->access(FixedActor::user(Users::OTHER_ID)))(new RevokePasskey(Users::ID, Uuid::v7()->toRfc4122()));
    }

    #[Test]
    public function it_should_verify_a_passkey_as_a_second_factor(): void
    {
        $handler = new VerifyPasskeyHandler($this->secondFactor(), new PasskeyCheck($this->ceremony));
        $handler(new VerifyPasskey(Users::ID, ScriptedCeremony::assertion('cred-1', count: 4), $accepted = Uuid::v7()->toRfc4122(), '{"get":1}'));
        $handler(new VerifyPasskey(Users::ID, ScriptedCeremony::assertion('cred-1', count: 4), $replayed = Uuid::v7()->toRfc4122(), '{}'));
        $handler(new VerifyPasskey(Users::ID, ScriptedCeremony::assertion('cred-1', count: 9, valid: false), $forged = Uuid::v7()->toRfc4122(), '{}'));
        $handler(new VerifyPasskey(Users::ID, ScriptedCeremony::assertion('cred-9', count: 9), $unknown = Uuid::v7()->toRfc4122(), '{}'));
        $handler(new VerifyPasskey(Users::ID, 'not json', $junk = Uuid::v7()->toRfc4122(), '{}'));

        self::assertSame([CodeVerificationOutcome::Accepted, CodeVerificationOutcome::Rejected, CodeVerificationOutcome::Rejected, CodeVerificationOutcome::Rejected, CodeVerificationOutcome::Rejected], array_map($this->outcome(...), [$accepted, $replayed, $forged, $unknown, $junk]));
        self::assertSame('assert cred-1 '.Users::ID.' {"get":1}', $this->ceremony->calls[0], 'Its own credential, against the options given.');
        self::assertSame(CodeKind::Passkey, $this->attempts->find(Uuid::fromString($accepted))?->kind());
        self::assertSame('identity.verify_passkey', $this->audit->entries[0][0]->auditAction()->name);
    }

    #[Test]
    public function it_should_refuse_a_code_that_is_not_a_passkey_answer(): void
    {
        $outcome = new PasskeyCheck($this->ceremony)->verify($this->user, new VerifyTotp(Users::ID, ScriptedCeremony::assertion('cred-1'), Uuid::v7()->toRfc4122()), $this->clock->now());

        self::assertSame(CodeVerificationOutcome::Rejected, $outcome);
        self::assertSame([], $this->ceremony->calls);
        self::assertSame([CodeKind::Passkey, CodeKind::PasskeyLogin], [new PasskeyCheck($this->ceremony)->kind(), new PasskeyCheck($this->ceremony)->forLogin()->kind()]);
    }

    #[Test]
    public function it_should_sign_in_with_a_passkey_without_any_session(): void
    {
        $handler = new AuthenticateWithPasskeyHandler($this->secondFactor(FixedActor::anonymous()), new PasskeyCheck($this->ceremony));
        $handler(new AuthenticateWithPasskey(Users::ID, ScriptedCeremony::assertion('cred-1', count: 4), $accepted = Uuid::v7()->toRfc4122(), '{}'));

        self::assertSame(CodeVerificationOutcome::Accepted, $this->outcome($accepted));
        self::assertSame(CodeKind::PasskeyLogin, $this->attempts->find(Uuid::fromString($accepted))?->kind());
        self::assertSame([
            'peek second_factor_short account:'.Users::ID,
            'peek second_factor_long account:'.Users::ID,
            'peek passkey_login_ip ip:203.0.113.7',
        ], $this->throttle->calls, 'Its own IP window.');
        self::assertSame(['identity.login_passkey', OutcomeKind::Success], [$this->audit->entries[0][0]->auditAction()->name, $this->audit->entries[0][1]->kind]);
    }

    #[Test]
    public function it_should_count_a_sign_in_for_an_unknown_account_and_alert_no_one(): void
    {
        $this->throttle->exhaustOnConsume(ThrottleName::SecondFactorLong);
        $missing = '019a6b2c-1f00-7a11-8b22-3c44d55e6f99';
        new AuthenticateWithPasskeyHandler($this->secondFactor(FixedActor::anonymous()), new PasskeyCheck($this->ceremony))(new AuthenticateWithPasskey($missing, ScriptedCeremony::assertion('cred-1', $missing), $attempt = Uuid::v7()->toRfc4122(), '{}'));

        self::assertSame(CodeVerificationOutcome::Rejected, $this->outcome($attempt));
        self::assertContains('consume passkey_login_ip ip:203.0.113.7', $this->throttle->calls);
        self::assertNotContains('consume second_factor_alert account:'.$missing, $this->throttle->calls, 'No account to alert.');
    }

    #[Test]
    public function it_should_refuse_a_valid_passkey_of_an_account_that_may_not_log_in(): void
    {
        $this->user->deactivate($this->clock->now());
        new AuthenticateWithPasskeyHandler($this->secondFactor(FixedActor::anonymous()), new PasskeyCheck($this->ceremony))(new AuthenticateWithPasskey(Users::ID, ScriptedCeremony::assertion('cred-1', count: 4), $attempt = Uuid::v7()->toRfc4122(), '{}'));

        self::assertSame(CodeVerificationOutcome::Rejected, $this->outcome($attempt));
    }

    #[Test]
    public function it_should_give_the_options_of_each_ceremony(): void
    {
        $creation = new GetPasskeyCreationOptionsHandler($this->access(), $this->users, $this->ceremony)(new GetPasskeyCreationOptions(Users::ID));
        self::assertSame('{"create":["'.Users::ID.'","jane@example.com","jane@example.com",["cred-1"]]}', $creation->json, 'Its passkeys excluded; the email when it has no name.');
        $this->user->rename('Jane Doe');
        self::assertStringContainsString('"Jane Doe"', new GetPasskeyCreationOptionsHandler($this->access(), $this->users, $this->ceremony)(new GetPasskeyCreationOptions(Users::ID))->json);

        $request = new GetPasskeyRequestOptionsHandler($this->access(), $this->users, $this->ceremony);
        self::assertEquals([new PasskeyOptions('{"get":["cred-1"]}'), new PasskeyOptions('{"get":[]}')], [$request(new GetPasskeyRequestOptions(Users::ID)), $request(new GetPasskeyRequestOptions())]);

        foreach ([static fn (self $t) => new GetPasskeyCreationOptionsHandler($t->access(FixedActor::user(Users::OTHER_ID)), $t->users, $t->ceremony)(new GetPasskeyCreationOptions(Users::ID)),
            static fn (self $t) => new GetPasskeyRequestOptionsHandler($t->access(FixedActor::user(Users::OTHER_ID)), $t->users, $t->ceremony)(new GetPasskeyRequestOptions(Users::ID))] as $other) {
            try {
                $other($this);
                self::fail('Another account.');
            } catch (PermissionDenied) {
            }
        }

        $gone = new InMemoryUsers();
        foreach ([fn () => new GetPasskeyCreationOptionsHandler($this->access(), $gone, $this->ceremony)(new GetPasskeyCreationOptions(Users::ID)),
            fn () => new GetPasskeyRequestOptionsHandler($this->access(), $gone, $this->ceremony)(new GetPasskeyRequestOptions(Users::ID))] as $missing) {
            try {
                $missing();
                self::fail('An account gone.');
            } catch (ResourceNotFound) {
            }
        }
    }

    #[Test]
    public function it_should_tell_the_account_of_a_recent_accepted_sign_in_only(): void
    {
        $now = $this->clock->now();
        $this->attempts->add(new CodeVerificationAttempt($accepted = Uuid::v7(), Uuid::fromString(Users::ID), CodeKind::PasskeyLogin, CodeVerificationOutcome::Accepted, $now->modify('-1 minute')));
        $this->attempts->add(new CodeVerificationAttempt($old = Uuid::v7(), Uuid::fromString(Users::ID), CodeKind::PasskeyLogin, CodeVerificationOutcome::Accepted, $now->modify('-61 seconds')));
        $this->attempts->add(new CodeVerificationAttempt($rejected = Uuid::v7(), Uuid::fromString(Users::ID), CodeKind::PasskeyLogin, CodeVerificationOutcome::Rejected, $now));
        $this->attempts->add(new CodeVerificationAttempt($totp = Uuid::v7(), Uuid::fromString(Users::ID), CodeKind::Totp, CodeVerificationOutcome::Accepted, $now));
        $query = new GetPasskeySignInHandler($this->attempts, $this->clock);

        self::assertSame(Users::ID, $query(new GetPasskeySignIn($accepted->toRfc4122())));
        foreach ([$old, $rejected, $totp, Uuid::v7()] as $id) {
            self::assertNull($query(new GetPasskeySignIn($id->toRfc4122())), 'Not an accepted recent passkey sign-in.');
        }
    }

    private function access(?FixedActor $actor = null, ?Grants $grants = null): AccountAccess
    {
        return new AccountAccess($actor ?? FixedActor::user(), $grants ?? new Grants(), $this->users);
    }

    private function secondFactor(?FixedActor $actor = null): SecondFactorAttempt
    {
        return new SecondFactorAttempt($this->access($actor), $this->attempts, $this->throttle, new ImmediateSection(), new FixedRequest(), $this->audit, $this->clock, $this->users);
    }

    private function outcome(string $attemptId): ?CodeVerificationOutcome
    {
        return $this->attempts->find(Uuid::fromString($attemptId))?->outcome();
    }
}
