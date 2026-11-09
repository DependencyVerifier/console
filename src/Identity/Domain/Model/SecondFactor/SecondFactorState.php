<?php

declare(strict_types=1);

namespace Identity\Domain\Model\SecondFactor;

use Identity\Domain\Model\SecondFactor;

/**
 * State pattern of the second factor: every state implements every operation. An operation the state does not
 * allow throws InvalidSecondFactorTransition, except the documented idempotent cases (reset is total, disabling a
 * pending enrolment abandons it, starting again replaces a pending secret).
 */
interface SecondFactorState
{
    public function name(): string;

    public function startTotpEnrollment(string $encryptedSecret): SecondFactor;

    /**
     * @param \Closure(string): ?int $matchingStep
     */
    public function confirmTotp(\Closure $matchingStep, \DateTimeImmutable $now): TotpVerification;

    /**
     * @param \Closure(string): ?int $matchingStep
     */
    public function verifyTotp(\Closure $matchingStep): TotpVerification;

    public function disableTotp(): SecondFactor;

    public function reset(): SecondFactor;

    /**
     * Email codes are the fallback only while TOTP is not active (decision 3).
     */
    public function allowsEmailCode(): bool;

    public function isTotpActive(): bool;
}
