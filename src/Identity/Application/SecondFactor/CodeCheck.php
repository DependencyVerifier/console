<?php

declare(strict_types=1);

namespace Identity\Application\SecondFactor;

use Identity\Domain\Model\CodeKind;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\User;

/**
 * Strategy of one code gesture. It only calls the aggregate method: it reads no hash and compares nothing itself.
 */
interface CodeCheck
{
    public function kind(): CodeKind;

    public function verify(User $user, CodeVerification $command, \DateTimeImmutable $now): CodeVerificationOutcome;
}
