<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\SecondFactor;

use Identity\Application\Command\SecondFactor\ConfirmTotp;
use Identity\Application\Command\SecondFactor\UseBackupCode;
use Identity\Application\Command\SecondFactor\VerifyEmailLoginCode;
use Identity\Application\Command\SecondFactor\VerifyTotp;
use Identity\Application\SecondFactor\BackupCodeCheck;
use Identity\Application\SecondFactor\EmailCodeCheck;
use Identity\Application\SecondFactor\SecondFactorAttempt;
use Identity\Application\SecondFactor\TotpCheck;
use Identity\Application\SecondFactor\TotpEnrollmentCheck;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Event\SecondFactorThrottled;
use Identity\Domain\Model\CodeKind;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\OutcomeKind;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Security\ThrottleName;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedRequest;
use Tests\Identity\Support\Grants;
use Tests\Identity\Support\ImmediateSection;
use Tests\Identity\Support\InMemoryAttempts;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\MappedTotpVerifier;
use Tests\Identity\Support\PrefixMatcher;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Identity\Support\RecordingAudit;
use Tests\Identity\Support\ScriptedThrottle;
use Tests\Identity\Support\TokenMatcher;
use Tests\Identity\Support\Users;

final class SecondFactorAttemptTest extends TestCase
{
    private InMemoryUsers $users;
    private InMemoryAttempts $attempts;
    private ScriptedThrottle $throttle;
    private ImmediateSection $section;
    private RecordingAudit $audit;
    private MockClock $clock;
    private User $user;

    protected function setUp(): void
    {
        $this->user = Users::withTotp(Users::member());
        $this->users = new InMemoryUsers($this->user);
        $this->attempts = new InMemoryAttempts();
        $this->throttle = new ScriptedThrottle();
        $this->section = new ImmediateSection();
        $this->audit = new RecordingAudit();
        $this->clock = new MockClock('2026-11-09 08:00:00');
    }

    #[Test]
    public function it_should_accept_a_right_code_without_consuming_any_limiter(): void
    {
        $command = $this->totp('111111');
        $outcome = $this->secondFactor()->run($command, new TotpCheck(new MappedTotpVerifier(['111111' => 200])));

        self::assertSame(CodeVerificationOutcome::Accepted, $outcome);
        self::assertSame([Users::ID], $this->users->locked);
        self::assertSame(['ip:203.0.113.7'], $this->section->subjects);
        self::assertSame([
            'peek second_factor_short account:'.Users::ID,
            'peek second_factor_long account:'.Users::ID,
            'peek second_factor_ip ip:203.0.113.7',
        ], $this->throttle->calls);
        $this->assertRecorded($command, CodeKind::Totp, CodeVerificationOutcome::Accepted);
        self::assertSame(OutcomeKind::Success, $this->audit->entries[0][1]->kind);
        self::assertEquals([[Users::ID, $this->clock->now()->modify('-1 hour')]], $this->attempts->pruned);
    }

    #[Test]
    public function it_should_consume_every_window_on_a_wrong_code_only(): void
    {
        $command = $this->totp('000000');
        $outcome = $this->secondFactor()->run($command, new TotpCheck(new MappedTotpVerifier([])));

        self::assertSame(CodeVerificationOutcome::Rejected, $outcome);
        self::assertSame([
            'consume second_factor_short account:'.Users::ID,
            'consume second_factor_long account:'.Users::ID,
            'consume second_factor_ip ip:203.0.113.7',
        ], \array_slice($this->throttle->calls, 3));
        self::assertSame(OutcomeKind::Failure, $this->audit->entries[0][1]->kind);
        self::assertSame('rejected', $this->audit->entries[0][1]->reason);
        self::assertSame([], $this->user->releaseEvents());
    }

    #[Test]
    public function it_should_not_evaluate_the_code_once_a_window_is_exhausted(): void
    {
        foreach ([ThrottleName::SecondFactorShort, ThrottleName::SecondFactorLong, ThrottleName::SecondFactorIp] as $window) {
            $this->setUp();
            $this->throttle->refuse($window);
            $verifier = new MappedTotpVerifier(['111111' => 200]);

            $outcome = $this->secondFactor()->run($this->totp('111111'), new TotpCheck($verifier));

            self::assertSame(CodeVerificationOutcome::Throttled, $outcome, $window->value);
            self::assertSame([], $verifier->asked, 'The code is never evaluated.');
            self::assertSame(OutcomeKind::Denied, $this->audit->entries[0][1]->kind);
            self::assertSame('throttled', $this->audit->entries[0][1]->reason);
            self::assertSame(CodeVerificationOutcome::Throttled, array_first($this->attempts->attempts)?->outcome());
        }
    }

    #[Test]
    public function it_should_alert_the_holder_on_the_first_block_of_the_long_window(): void
    {
        $this->throttle->exhaustOnConsume(ThrottleName::SecondFactorLong);

        $this->secondFactor()->run($this->totp('000000'), new TotpCheck(new MappedTotpVerifier([])));

        self::assertEquals([new SecondFactorThrottled(Users::ID, $this->throttle->retryAfter)], $this->user->releaseEvents());
        self::assertContains('consume second_factor_alert account:'.Users::ID, $this->throttle->calls);
    }

    #[Test]
    public function it_should_alert_at_most_once_per_alert_window(): void
    {
        $this->throttle->exhaustOnConsume(ThrottleName::SecondFactorLong)->refuse(ThrottleName::SecondFactorAlert);
        $this->throttle->calls = [];
        $throttle = new ScriptedThrottle();
        $throttle->exhaustOnConsume(ThrottleName::SecondFactorLong);
        $throttle->refuse(ThrottleName::SecondFactorAlert);
        $this->throttle = $throttle;

        $this->secondFactor()->run($this->totp('000000'), new TotpCheck(new MappedTotpVerifier([])));

        self::assertSame([], $this->user->releaseEvents());
    }

    #[Test]
    public function it_should_not_alert_when_another_window_is_exhausted(): void
    {
        $this->throttle->exhaustOnConsume(ThrottleName::SecondFactorShort)->exhaustOnConsume(ThrottleName::SecondFactorIp);

        $this->secondFactor()->run($this->totp('000000'), new TotpCheck(new MappedTotpVerifier([])));

        self::assertSame([], $this->user->releaseEvents());
        self::assertNotContains('consume second_factor_alert account:'.Users::ID, $this->throttle->calls);
    }

    #[Test]
    public function it_should_answer_throttled_on_lock_contention_and_audit_it_apart(): void
    {
        $this->section = new ImmediateSection(contended: true);

        $outcome = $this->secondFactor()->run($this->totp('111111'), new TotpCheck(new MappedTotpVerifier(['111111' => 200])));

        self::assertSame(CodeVerificationOutcome::Throttled, $outcome);
        self::assertSame('lock_contention', $this->audit->entries[0][1]->reason);
        self::assertSame(OutcomeKind::Denied, $this->audit->entries[0][1]->kind);
    }

    #[Test]
    public function it_should_audit_exhausted_and_expired_codes_as_failures(): void
    {
        $member = Users::member(Users::OTHER_ID, 'john@example.com');
        $this->users = new InMemoryUsers($member);
        $command = new VerifyEmailLoginCode(Users::OTHER_ID, '123456', Uuid::v7()->toRfc4122());

        $outcome = $this->secondFactor(FixedActor::user(Users::OTHER_ID))->run($command, new EmailCodeCheck(new PrefixMatcher()));

        self::assertSame(CodeVerificationOutcome::Expired, $outcome);
        self::assertSame('expired', $this->audit->entries[0][1]->reason);
        $this->assertRecorded($command, CodeKind::EmailCode, CodeVerificationOutcome::Expired);
    }

    #[Test]
    public function it_should_use_an_unknown_ip_key_outside_a_request(): void
    {
        $this->secondFactor(request: new FixedRequest(null))->run($this->totp('111111'), new TotpCheck(new MappedTotpVerifier(['111111' => 200])));

        self::assertSame(['ip:unknown'], $this->section->subjects);
    }

    #[Test]
    public function it_should_verify_the_holder_codes_only(): void
    {
        $this->expectException(PermissionDenied::class);

        $this->secondFactor(FixedActor::user(Users::OTHER_ID))->run($this->totp('111111'), new TotpCheck(new MappedTotpVerifier([])));
    }

    #[Test]
    public function it_should_answer_not_found_for_an_unknown_account(): void
    {
        $this->users = new InMemoryUsers();

        $this->expectException(ResourceNotFound::class);
        $this->secondFactor()->run($this->totp('111111'), new TotpCheck(new MappedTotpVerifier([])));
    }

    #[Test]
    public function it_should_run_each_strategy_on_its_aggregate_method(): void
    {
        $backup = new UseBackupCode(Users::ID, 'aaaaa-11111', Uuid::v7()->toRfc4122());
        self::assertSame(CodeVerificationOutcome::Accepted, $this->secondFactor()->run($backup, new BackupCodeCheck(new PrefixMatcher())));
        $this->assertRecorded($backup, CodeKind::BackupCode, CodeVerificationOutcome::Accepted);

        $pending = Users::member();
        $pending->startTotpEnrollment('secret');
        $this->users = new InMemoryUsers($pending);
        $confirm = new ConfirmTotp(Users::ID, '654321', Uuid::v7()->toRfc4122(), ['ccccc-33333']);
        $check = new TotpEnrollmentCheck(new MappedTotpVerifier(['654321' => 300]), new PrefixTokenHasher());

        self::assertSame(CodeVerificationOutcome::Accepted, $this->secondFactor()->run($confirm, $check));
        self::assertSame(CodeKind::TotpEnrollment, $check->kind());
        self::assertSame(CodeVerificationOutcome::Accepted, $pending->useBackupCode('ccccc-33333', new TokenMatcher(), $this->clock->now()));
    }

    #[Test]
    public function it_should_give_each_strategy_its_kind(): void
    {
        self::assertSame(CodeKind::Totp, new TotpCheck(new MappedTotpVerifier([]))->kind());
        self::assertSame(CodeKind::EmailCode, new EmailCodeCheck(new PrefixMatcher())->kind());
        self::assertSame(CodeKind::BackupCode, new BackupCodeCheck(new PrefixMatcher())->kind());
    }

    #[Test]
    public function it_should_confirm_with_a_confirm_command_only(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TotpEnrollmentCheck(new MappedTotpVerifier([]), new PrefixTokenHasher())->verify($this->user, $this->totp('1'), $this->clock->now());
    }

    private function secondFactor(?FixedActor $actor = null, ?FixedRequest $request = null): SecondFactorAttempt
    {
        return new SecondFactorAttempt(
            new AccountAccess($actor ?? FixedActor::user(), new Grants(), $this->users),
            $this->attempts,
            $this->throttle,
            $this->section,
            $request ?? new FixedRequest(),
            $this->audit,
            $this->clock,
        );
    }

    private function totp(string $code): VerifyTotp
    {
        return new VerifyTotp(Users::ID, $code, Uuid::v7()->toRfc4122());
    }

    private function assertRecorded(VerifyTotp|VerifyEmailLoginCode|UseBackupCode $command, CodeKind $kind, CodeVerificationOutcome $outcome): void
    {
        $attempt = $this->attempts->find(Uuid::fromString($command->attemptId));
        self::assertNotNull($attempt);
        self::assertSame($kind, $attempt->kind());
        self::assertSame($outcome, $attempt->outcome());
        self::assertSame($command->userId, $attempt->userId()->toString());
        self::assertEquals($this->clock->now(), $attempt->createdAt());
        self::assertCount(1, $this->audit->entries);
        self::assertSame($command, $this->audit->entries[0][0]);
    }
}
