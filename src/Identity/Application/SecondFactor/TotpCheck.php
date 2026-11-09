<?php

declare(strict_types=1);

namespace Identity\Application\SecondFactor;

use Identity\Domain\Model\CodeKind;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\User;
use Identity\Domain\Security\TotpVerifier;

final readonly class TotpCheck implements CodeCheck
{
    public function __construct(private TotpVerifier $verifier)
    {
    }

    public function kind(): CodeKind
    {
        return CodeKind::Totp;
    }

    public function verify(User $user, CodeVerification $command, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        return $user->verifyTotp($command->code, $this->verifier, $now);
    }
}
