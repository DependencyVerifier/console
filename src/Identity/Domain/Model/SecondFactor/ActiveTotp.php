<?php

declare(strict_types=1);

namespace Identity\Domain\Model\SecondFactor;

use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\InvalidSecondFactorTransition;
use Identity\Domain\Model\SecondFactor;

/**
 * Confirmed TOTP. `lastAcceptedStep` refuses a replayed code: a step lower than or equal to it is rejected.
 */
final readonly class ActiveTotp implements SecondFactorState
{
    public function __construct(private string $encryptedSecret, private \DateTimeImmutable $since, private int $lastAcceptedStep)
    {
    }

    public function name(): string
    {
        return 'active_totp';
    }

    public function startTotpEnrollment(string $encryptedSecret): SecondFactor
    {
        throw new InvalidSecondFactorTransition('start_totp_enrollment', $this->name());
    }

    public function confirmTotp(\Closure $matchingStep, \DateTimeImmutable $now): TotpVerification
    {
        throw new InvalidSecondFactorTransition('confirm_totp', $this->name());
    }

    public function verifyTotp(\Closure $matchingStep): TotpVerification
    {
        $unchanged = SecondFactor::activeTotp($this->encryptedSecret, $this->since, $this->lastAcceptedStep);
        $step = $matchingStep($this->encryptedSecret);
        if (null === $step || $step <= $this->lastAcceptedStep) {
            return new TotpVerification(CodeVerificationOutcome::Rejected, $unchanged);
        }

        return new TotpVerification(CodeVerificationOutcome::Accepted, SecondFactor::activeTotp($this->encryptedSecret, $this->since, $step));
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
        return false;
    }

    public function isTotpActive(): bool
    {
        return true;
    }
}
