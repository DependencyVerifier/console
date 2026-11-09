<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Domain\Model;

use Identity\Domain\Event\EmailLoginCodeIssued;
use Identity\Domain\Event\PasswordResetRequested;
use Identity\Domain\Model\BackupCode;
use Identity\Domain\Model\CodeKind;
use Identity\Domain\Model\CodeVerificationAttempt;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\EmailLoginCode;
use Identity\Domain\Model\PasswordResetLinkInvalid;
use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\PrefixMatcher;
use Tests\Identity\Support\Users;

final class CodesTest extends TestCase
{
    private const string ISSUED = '2026-11-09 08:00:00';

    #[Test]
    public function it_should_accept_a_right_email_code_once(): void
    {
        $code = $this->emailCode();
        $attempt = Uuid::v7();

        self::assertSame(CodeVerificationOutcome::Accepted, $code->verify('123456', new PrefixMatcher(), $attempt, $this->at('+9 minutes 59 seconds')));
        self::assertFalse($code->isActive());
        self::assertSame($attempt, $code->consumedBy());
        self::assertSame(CodeVerificationOutcome::Expired, $code->verify('123456', new PrefixMatcher(), Uuid::v7(), $this->at('+1 minute')), 'Single use.');
    }

    #[Test]
    public function it_should_count_tries_and_refuse_the_sixth_even_when_right(): void
    {
        $code = $this->emailCode();
        for ($i = 1; $i <= EmailLoginCode::MAX_ATTEMPTS; ++$i) {
            self::assertSame(CodeVerificationOutcome::Rejected, $code->verify('000000', new PrefixMatcher(), Uuid::v7(), $this->at('+1 minute')));
            self::assertSame($i, $code->attempts());
        }

        self::assertSame(CodeVerificationOutcome::Exhausted, $code->verify('123456', new PrefixMatcher(), Uuid::v7(), $this->at('+1 minute')));
        self::assertSame(5, $code->attempts());
        self::assertTrue($code->isActive());
    }

    #[Test]
    public function it_should_expire_after_ten_minutes_and_invalidate(): void
    {
        $code = $this->emailCode();
        $now = $this->at('+10 minutes');

        self::assertSame(CodeVerificationOutcome::Expired, $code->verify('123456', new PrefixMatcher(), Uuid::v7(), $now));
        self::assertFalse($code->isActive());
        self::assertSame(0, $code->attempts());
    }

    #[Test]
    public function it_should_invalidate_an_active_code_only(): void
    {
        $code = $this->emailCode();
        $code->verify('123456', new PrefixMatcher(), Uuid::v7(), $this->at('+1 minute'));
        $consumedBy = $code->consumedBy();
        $code->invalidate($this->at('+2 minutes'));
        self::assertSame($consumedBy, $code->consumedBy());

        $other = $this->emailCode();
        $other->invalidate($this->at('+2 minutes'));
        self::assertFalse($other->isActive());

        $this->expectException(\LogicException::class);
        $other->consumedBy();
    }

    #[Test]
    public function it_should_expose_its_owner_and_dates(): void
    {
        $user = Users::member();
        $id = Uuid::v7();
        $code = new EmailLoginCode($id, $user, PrefixMatcher::hash('123456'), new \DateTimeImmutable(self::ISSUED));

        self::assertSame($id, $code->id());
        self::assertSame($user, $code->user());
        self::assertEquals(new \DateTimeImmutable(self::ISSUED), $code->issuedAt());

        $backup = new BackupCode($id, $user, PrefixMatcher::hash('aaaaa-11111'));
        self::assertSame($id, $backup->id());
        self::assertSame($user, $backup->user());
    }

    #[Test]
    public function it_should_use_a_backup_code_once(): void
    {
        $backup = new BackupCode(Uuid::v7(), Users::member(), PrefixMatcher::hash('aaaaa-11111'));

        self::assertFalse($backup->use('ccccc-33333', new PrefixMatcher(), new \DateTimeImmutable()));
        self::assertFalse($backup->isUsed());
        self::assertTrue($backup->use('aaaaa-11111', new PrefixMatcher(), new \DateTimeImmutable()));
        self::assertTrue($backup->isUsed());
        self::assertFalse($backup->use('aaaaa-11111', new PrefixMatcher(), new \DateTimeImmutable()));
    }

    #[Test]
    public function it_should_issue_a_single_use_reset_link_for_thirty_minutes(): void
    {
        $id = Uuid::v7();
        $userId = UserId::fromString(Users::ID);
        $request = PasswordResetRequest::issue($id, $userId, 'plain-token', 'h:plain-token', new \DateTimeImmutable(self::ISSUED));

        self::assertEquals([new PasswordResetRequested($id->toRfc4122(), Users::ID, 'plain-token')], $request->releaseEvents());
        self::assertSame($id, $request->id());
        self::assertTrue($request->userId()->equals($userId));
        self::assertTrue($request->isUsableAt($this->at('+29 minutes 59 seconds')));
        self::assertFalse($request->isUsableAt($this->at('+30 minutes')));

        $request->consume($this->at('+5 minutes'));
        self::assertFalse($request->isUsableAt($this->at('+6 minutes')));
        $request->invalidate($this->at('+7 minutes'));

        $this->expectException(PasswordResetLinkInvalid::class);
        $request->consume($this->at('+8 minutes'));
    }

    #[Test]
    public function it_should_refuse_an_invalidated_or_expired_link(): void
    {
        $invalidated = PasswordResetRequest::issue(Uuid::v7(), UserId::fromString(Users::ID), 't', 'h', new \DateTimeImmutable(self::ISSUED));
        $invalidated->invalidate($this->at('+1 minute'));
        $invalidated->invalidate($this->at('+2 minutes'));
        self::assertFalse($invalidated->isUsableAt($this->at('+3 minutes')));

        $expired = PasswordResetRequest::issue(Uuid::v7(), UserId::fromString(Users::ID), 't', 'h', new \DateTimeImmutable(self::ISSUED));
        $this->expectException(PasswordResetLinkInvalid::class);
        $expired->consume($this->at('+31 minutes'));
    }

    #[Test]
    public function it_should_never_serialize_an_event_carrying_a_secret(): void
    {
        foreach ([new PasswordResetRequested('r', Users::ID, 'plain-token'), new EmailLoginCodeIssued(Users::ID, '123456')] as $event) {
            try {
                serialize($event);
                self::fail($event::class.' must not be serialized.');
            } catch (\LogicException $refused) {
                self::assertSame('An event carrying a secret is never serialized.', $refused->getMessage());
            }
        }
    }

    #[Test]
    public function it_should_record_a_verification_attempt(): void
    {
        $at = new \DateTimeImmutable(self::ISSUED);
        $id = Uuid::v7();
        $attempt = new CodeVerificationAttempt($id, Uuid::fromString(Users::ID), CodeKind::Totp, CodeVerificationOutcome::Rejected, $at);

        self::assertSame($id, $attempt->id());

        self::assertSame(CodeVerificationOutcome::Rejected, $attempt->outcome());
        self::assertSame(Users::ID, $attempt->userId()->toString());
        self::assertSame(CodeKind::Totp, $attempt->kind());
        self::assertSame($at, $attempt->createdAt());
    }

    private function emailCode(): EmailLoginCode
    {
        return new EmailLoginCode(Uuid::v7(), Users::member(), PrefixMatcher::hash('123456'), new \DateTimeImmutable(self::ISSUED));
    }

    private function at(string $offset): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::ISSUED)->modify($offset);
    }
}
