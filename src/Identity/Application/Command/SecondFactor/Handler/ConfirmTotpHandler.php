<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor\Handler;

use Identity\Application\Command\SecondFactor\ConfirmTotp;
use Identity\Application\SecondFactor\SecondFactorAttempt;
use Identity\Application\SecondFactor\TotpEnrollmentCheck;
use Shared\Application\Bus\CommandHandler;

final readonly class ConfirmTotpHandler implements CommandHandler
{
    public function __construct(private SecondFactorAttempt $attempt, private TotpEnrollmentCheck $check)
    {
    }

    public function __invoke(ConfirmTotp $command): void
    {
        $this->attempt->run($command, $this->check);
    }
}
