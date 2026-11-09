<?php

declare(strict_types=1);

namespace Identity\Application\SecondFactor;

use Identity\Domain\Model\CodeKind;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\User;
use Identity\Domain\Security\CodeHashMatcher;

final readonly class BackupCodeCheck implements CodeCheck
{
    public function __construct(private CodeHashMatcher $matcher)
    {
    }

    public function kind(): CodeKind
    {
        return CodeKind::BackupCode;
    }

    public function verify(User $user, CodeVerification $command, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        return $user->useBackupCode($command->code, $this->matcher, $now);
    }
}
