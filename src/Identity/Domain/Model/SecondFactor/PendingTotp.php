<?php

declare(strict_types=1);

namespace Identity\Domain\Model\SecondFactor;

use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\InvalidSecondFactorTransition;
use Identity\Domain\Model\SecondFactor;

/**
 * A secret was generated but not confirmed yet: the email code is still the second factor.
 */
final readonly class PendingTotp implements SecondFactorState
{
    public function __construct(private string $encryptedSecret)
    {
    }

    public function name(): string
    {
        return 'pending_totp';
    }

    /**
     * Shown once on the enrolment screen (QR code), decrypted by the UI adapter.
     */
    public function encryptedSecret(): string
    {
        return $this->encryptedSecret;
    }

    public function startTotpEnrollment(string $encryptedSecret): SecondFactor
    {
        return SecondFactor::pendingTotp($encryptedSecret);
    }

    public function confirmTotp(\Closure $matchingStep, \DateTimeImmutable $now): TotpVerification
    {
        $step = $matchingStep($this->encryptedSecret);
        if (null === $step) {
            return new TotpVerification(CodeVerificationOutcome::Rejected, SecondFactor::pendingTotp($this->encryptedSecret));
        }

        return new TotpVerification(CodeVerificationOutcome::Accepted, SecondFactor::activeTotp($this->encryptedSecret, $now, $step));
    }

    public function verifyTotp(\Closure $matchingStep): TotpVerification
    {
        throw new InvalidSecondFactorTransition('verify_totp', $this->name());
    }

    public function disableTotp(): SecondFactor
    {
        return SecondFactor::notConfigured();
    }

    public function reset(): SecondFactor
    {
        return SecondFactor::notConfigured();
    }

    public function allowsEmailCode(): bool
    {
        return true;
    }

    public function isTotpActive(): bool
    {
        return false;
    }
}
