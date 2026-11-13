<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Domain\Model;

use Identity\Domain\Event\PasskeyRegistered;
use Identity\Domain\Event\PasskeyRevoked;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\InvalidPasskeyName;
use Identity\Domain\Model\NewPasskey;
use Identity\Domain\Model\PasskeyLimitReached;
use Identity\Domain\Model\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\Users;

use function Symfony\Component\String\u;

final class PasskeysTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-15 08:00:00');
    }

    #[Test]
    public function it_should_register_a_named_passkey_without_signing_out(): void
    {
        $user = Users::member();
        $versions = $user->sessionVersions();
        $id = Uuid::v7();
        $user->registerPasskey($id, $this->passkey('cred-1', 7), '  YubiKey office  ', $this->now);

        self::assertCount(1, $user->passkeys());
        $passkey = $user->passkeys()[0];
        self::assertSame([$id, 'cred-1', 'cose:cred-1', 7, ['usb', 'nfc'], '0d5c4f7f-0000-4000-8000-000000000001', 'YubiKey office', null], [
            $passkey->id(), $passkey->credentialId(), $passkey->publicKey(), $passkey->signCount(), $passkey->transports(), $passkey->aaguid(), $passkey->name(), $passkey->lastUsedAt(),
        ]);
        self::assertEquals($this->now, $passkey->createdAt());
        self::assertTrue($user->hasPasskey($id));
        self::assertFalse($user->hasPasskey(Uuid::v7()));
        self::assertEquals($versions, $user->sessionVersions(), 'Adding a way in takes none away.');
        self::assertEquals([new PasskeyRegistered(Users::ID)], $user->releaseEvents());
    }

    #[Test]
    public function it_should_refuse_an_empty_long_or_taken_name(): void
    {
        $user = Users::member();
        $user->registerPasskey(Uuid::v7(), $this->passkey('cred-1'), 'iCloud', $this->now);
        foreach (['', '   ', u('a')->repeat(User::PASSKEY_NAME_MAX + 1)->toString(), 'ICLOUD'] as $name) {
            try {
                $user->registerPasskey(Uuid::v7(), $this->passkey('cred-2'), $name, $this->now);
                self::fail(\sprintf('"%s" accepted.', $name));
            } catch (InvalidPasskeyName $refused) {
                self::assertSame('invalid_passkey_name', $refused->ruleKey());
            }
        }

        $user->registerPasskey(Uuid::v7(), $this->passkey('cred-2'), u('b')->repeat(User::PASSKEY_NAME_MAX)->toString(), $this->now);
        self::assertCount(2, $user->passkeys());
    }

    #[Test]
    public function it_should_hold_ten_passkeys_at_most(): void
    {
        $user = Users::member();
        for ($i = 1; $i <= User::PASSKEYS_MAX; ++$i) {
            $user->registerPasskey(Uuid::v7(), $this->passkey('cred-'.$i), 'Key '.$i, $this->now);
        }

        try {
            $user->registerPasskey(Uuid::v7(), $this->passkey('cred-11'), 'Key 11', $this->now);
            self::fail('An eleventh passkey.');
        } catch (PasskeyLimitReached $refused) {
            self::assertSame('passkey_limit_reached', $refused->ruleKey());
        }
    }

    #[Test]
    public function it_should_sign_every_session_out_when_a_passkey_is_revoked(): void
    {
        $user = Users::member();
        $kept = Uuid::v7();
        $revoked = Uuid::v7();
        $user->registerPasskey($kept, $this->passkey('cred-1'), 'Phone', $this->now);
        $user->registerPasskey($revoked, $this->passkey('cred-2'), 'Laptop', $this->now);
        $user->releaseEvents();
        $versions = $user->sessionVersions();

        $user->revokePasskey(Uuid::v7());
        self::assertEquals($versions, $user->sessionVersions(), 'Nothing for an unknown passkey.');
        self::assertSame([], $user->releaseEvents());

        $user->revokePasskey($revoked);
        self::assertSame(['cred-1'], array_map(static fn ($p): string => $p->credentialId(), $user->passkeys()));
        self::assertNotEquals($versions->authVersion(), $user->sessionVersions()->authVersion());
        self::assertNotEquals($versions->trustedDeviceVersion(), $user->sessionVersions()->trustedDeviceVersion());
        self::assertEquals([new PasskeyRevoked(Users::ID)], $user->releaseEvents());
    }

    #[Test]
    public function it_should_accept_a_growing_counter_only(): void
    {
        $user = Users::member();
        $user->registerPasskey(Uuid::v7(), $this->passkey('cred-1', 5), 'Key', $this->now);
        $later = $this->now->modify('+1 hour');

        self::assertSame(CodeVerificationOutcome::Rejected, $user->usePasskey('cred-1', 5, $later), 'A counter that does not grow: a clone.');
        self::assertSame(CodeVerificationOutcome::Rejected, $user->usePasskey('cred-1', 4, $later));
        self::assertNull($user->passkeys()[0]->lastUsedAt(), 'A refused signature changes nothing.');
        self::assertSame(CodeVerificationOutcome::Rejected, $user->usePasskey('other', 9, $later), 'Not a passkey of this account.');
        self::assertSame(CodeVerificationOutcome::Accepted, $user->usePasskey('cred-1', 6, $later));
        self::assertSame([6, $later], [$user->passkeys()[0]->signCount(), $user->passkeys()[0]->lastUsedAt()]);
        self::assertSame(CodeVerificationOutcome::Rejected, $user->usePasskey('cred-1', 0, $later), 'Back to 0 after a count.');
    }

    #[Test]
    public function it_should_accept_an_authenticator_always_reporting_zero(): void
    {
        $user = Users::member();
        $user->registerPasskey(Uuid::v7(), $this->passkey('cred-1'), 'iCloud', $this->now);

        self::assertSame(CodeVerificationOutcome::Accepted, $user->usePasskey('cred-1', 0, $this->now));
        self::assertSame(CodeVerificationOutcome::Accepted, $user->usePasskey('cred-1', 0, $this->now), 'Many authenticators never count.');
        self::assertSame(CodeVerificationOutcome::Accepted, $user->usePasskey('cred-1', 1, $this->now));
    }

    private function passkey(string $credentialId, int $signCount = 0): NewPasskey
    {
        return new NewPasskey($credentialId, 'cose:'.$credentialId, $signCount, ['usb', 'nfc'], '0d5c4f7f-0000-4000-8000-000000000001');
    }
}
