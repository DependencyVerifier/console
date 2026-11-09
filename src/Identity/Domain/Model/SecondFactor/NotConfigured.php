<?php

declare(strict_types=1);

namespace Identity\Domain\Model\SecondFactor;

use Identity\Domain\Model\InvalidSecondFactorTransition;
use Identity\Domain\Model\SecondFactor;

final readonly class NotConfigured implements SecondFactorState
{
    public function name(): string
    {
        return 'not_configured';
    }

    public function startTotpEnrollment(string $encryptedSecret): SecondFactor
    {
        return SecondFactor::pendingTotp($encryptedSecret);
    }

    public function confirmTotp(\Closure $matchingStep, \DateTimeImmutable $now): TotpVerification
    {
        throw new InvalidSecondFactorTransition('confirm_totp', $this->name());
    }

    public function verifyTotp(\Closure $matchingStep): TotpVerification
    {
        throw new InvalidSecondFactorTransition('verify_totp', $this->name());
    }

    public function disableTotp(): SecondFactor
    {
        throw new InvalidSecondFactorTransition('disable_totp', $this->name());
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
