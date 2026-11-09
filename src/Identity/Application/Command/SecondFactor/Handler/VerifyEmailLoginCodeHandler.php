<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor\Handler;

use Identity\Application\Command\SecondFactor\VerifyEmailLoginCode;
use Identity\Application\SecondFactor\EmailCodeCheck;
use Identity\Application\SecondFactor\SecondFactorAttempt;
use Shared\Application\Bus\CommandHandler;

final readonly class VerifyEmailLoginCodeHandler implements CommandHandler
{
    public function __construct(private SecondFactorAttempt $attempt, private EmailCodeCheck $check)
    {
    }

    public function __invoke(VerifyEmailLoginCode $command): void
    {
        $this->attempt->run($command, $this->check);
    }
}
