<?php

declare(strict_types=1);

namespace Identity\Application\SecondFactor;

use Identity\Domain\Model\CodeKind;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\User;
use Identity\Domain\Security\CodeHashMatcher;
use Symfony\Component\Uid\Uuid;

final readonly class EmailCodeCheck implements CodeCheck
{
    public function __construct(private CodeHashMatcher $matcher)
    {
    }

    public function kind(): CodeKind
    {
        return CodeKind::EmailCode;
    }

    public function verify(User $user, CodeVerification $command, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        return $user->verifyEmailLoginCode($command->code, $this->matcher, Uuid::fromString($command->attemptId), $now);
    }
}
