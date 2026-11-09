<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Domain\Model;

use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\InvalidSecondFactorTransition;
use Identity\Domain\Model\SecondFactor;
use Identity\Domain\Model\SecondFactor\ActiveTotp;
use Identity\Domain\Model\SecondFactor\NotConfigured;
use Identity\Domain\Model\SecondFactor\PendingTotp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every state × operation: allowed, idempotent no-op, or InvalidSecondFactorTransition.
 */
final class SecondFactorTest extends TestCase
{
    #[Test]
    public function it_should_rebuild_each_state(): void
    {
        $since = new \DateTimeImmutable('2026-11-09');

        self::assertInstanceOf(NotConfigured::class, SecondFactor::notConfigured()->state());
        self::assertSame('not_configured', SecondFactor::notConfigured()->state()->name());
        self::assertInstanceOf(PendingTotp::class, SecondFactor::pendingTotp('s')->state());
        self::assertSame('pending_totp', SecondFactor::pendingTotp('s')->state()->name());
        self::assertSame('s', $this->pending('s')->encryptedSecret());
        self::assertInstanceOf(ActiveTotp::class, SecondFactor::activeTotp('s', $since, 7)->state());
        self::assertSame('active_totp', SecondFactor::activeTotp('s', $since, 7)->state()->name());
    }

    #[Test]
    public function it_should_refuse_a_persisted_state_missing_a_field(): void
    {
        $broken = SecondFactor::pendingTotp('s');
        new \ReflectionProperty(SecondFactor::class, 'encryptedSecret')->setValue($broken, null);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Second factor "pending_totp" is missing a required field.');
        $broken->state();
    }

    #[Test]
    public function it_should_refuse_an_unknown_kind(): void
    {
        $broken = SecondFactor::notConfigured();
        new \ReflectionProperty(SecondFactor::class, 'kind')->setValue($broken, 'sms');

        $this->expectException(\UnexpectedValueException::class);
        $broken->state();
    }

    #[Test]
    public function it_should_allow_the_email_code_until_totp_is_active(): void
    {
        self::assertTrue(new NotConfigured()->allowsEmailCode());
        self::assertTrue($this->pending()->allowsEmailCode());
        self::assertFalse($this->active()->allowsEmailCode());
        self::assertFalse(new NotConfigured()->isTotpActive());
        self::assertFalse($this->pending()->isTotpActive());
        self::assertTrue($this->active()->isTotpActive());
    }

    #[Test]
    public function it_should_start_an_enrolment_or_replace_a_pending_secret(): void
    {
        self::assertEquals(SecondFactor::pendingTotp('new'), new NotConfigured()->startTotpEnrollment('new'));
        self::assertEquals(SecondFactor::pendingTotp('new'), $this->pending('old')->startTotpEnrollment('new'));
    }

    #[Test]
    public function it_should_confirm_a_pending_enrolment_on_a_matching_code_only(): void
    {
        $now = new \DateTimeImmutable('2026-11-09 08:00:00');
        $seen = [];
        $accepted = $this->pending('s')->confirmTotp(static function (string $secret) use (&$seen): int {
            $seen[] = $secret;

            return 42;
        }, $now);
        $rejected = $this->pending('s')->confirmTotp(static fn (): ?int => null, $now);

        self::assertSame(['s'], $seen);
        self::assertSame(CodeVerificationOutcome::Accepted, $accepted->outcome);
        self::assertEquals(SecondFactor::activeTotp('s', $now, 42), $accepted->next);
        self::assertSame(CodeVerificationOutcome::Rejected, $rejected->outcome);
        self::assertEquals(SecondFactor::pendingTotp('s'), $rejected->next);
    }

    #[Test]
    public function it_should_accept_a_later_step_only(): void
    {
        $since = new \DateTimeImmutable('2026-11-01');
        $active = new ActiveTotp('s', $since, 10);

        $later = $active->verifyTotp(static fn (string $secret): int => 's' === $secret ? 11 : 0);
        $same = $active->verifyTotp(static fn (): int => 10);
        $earlier = $active->verifyTotp(static fn (): int => 9);
        $none = $active->verifyTotp(static fn (): ?int => null);

        self::assertSame(CodeVerificationOutcome::Accepted, $later->outcome);
        self::assertEquals(SecondFactor::activeTotp('s', $since, 11), $later->next);
        foreach ([$same, $earlier, $none] as $refused) {
            self::assertSame(CodeVerificationOutcome::Rejected, $refused->outcome);
            self::assertEquals(SecondFactor::activeTotp('s', $since, 10), $refused->next);
        }
    }

    #[Test]
    public function it_should_disable_or_reset_back_to_not_configured(): void
    {
        self::assertEquals(SecondFactor::notConfigured(), $this->active()->disableTotp());
        self::assertEquals(SecondFactor::notConfigured(), $this->pending()->disableTotp(), 'Abandons the enrolment.');
        foreach ([new NotConfigured(), $this->pending(), $this->active()] as $state) {
            self::assertEquals(SecondFactor::notConfigured(), $state->reset(), 'Reset is total.');
        }
    }

    /**
     * @return iterable<string, array{string, \Closure(): mixed}>
     */
    public static function invalidTransitions(): iterable
    {
        $step = static fn (): int => 1;
        $now = new \DateTimeImmutable();
        $active = new ActiveTotp('s', $now, 1);
        $pending = new PendingTotp('s');
        $none = new NotConfigured();

        yield 'confirm not configured' => ['Cannot "confirm_totp" a second factor in state "not_configured".', static fn () => $none->confirmTotp($step, $now)];
        yield 'verify not configured' => ['Cannot "verify_totp" a second factor in state "not_configured".', static fn () => $none->verifyTotp($step)];
        yield 'disable not configured' => ['Cannot "disable_totp" a second factor in state "not_configured".', $none->disableTotp(...)];
        yield 'verify pending' => ['Cannot "verify_totp" a second factor in state "pending_totp".', static fn () => $pending->verifyTotp($step)];
        yield 'start active' => ['Cannot "start_totp_enrollment" a second factor in state "active_totp".', static fn () => $active->startTotpEnrollment('x')];
        yield 'confirm active' => ['Cannot "confirm_totp" a second factor in state "active_totp".', static fn () => $active->confirmTotp($step, $now)];
    }

    /**
     * @param \Closure(): mixed $operation
     */
    #[Test]
    #[DataProvider('invalidTransitions')]
    public function it_should_refuse_an_invalid_transition(string $message, \Closure $operation): void
    {
        $this->expectException(InvalidSecondFactorTransition::class);
        $this->expectExceptionMessageIsOrContains($message);

        $operation();
    }

    private function pending(string $secret = 's'): PendingTotp
    {
        return new PendingTotp($secret);
    }

    private function active(): ActiveTotp
    {
        return new ActiveTotp('s', new \DateTimeImmutable('2026-11-01'), 1);
    }
}
