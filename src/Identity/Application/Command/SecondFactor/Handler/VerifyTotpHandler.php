<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor\Handler;

use Identity\Application\Command\SecondFactor\VerifyTotp;
use Identity\Application\SecondFactor\SecondFactorAttempt;
use Identity\Application\SecondFactor\TotpCheck;
use Shared\Application\Bus\CommandHandler;

final readonly class VerifyTotpHandler implements CommandHandler
{
    public function __construct(private SecondFactorAttempt $attempt, private TotpCheck $check)
    {
    }

    public function __invoke(VerifyTotp $command): void
    {
        $this->attempt->run($command, $this->check);
    }
}
