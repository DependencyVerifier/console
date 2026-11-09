<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor\Handler;

use Identity\Application\Command\SecondFactor\UseBackupCode;
use Identity\Application\SecondFactor\BackupCodeCheck;
use Identity\Application\SecondFactor\SecondFactorAttempt;
use Shared\Application\Bus\CommandHandler;

final readonly class UseBackupCodeHandler implements CommandHandler
{
    public function __construct(private SecondFactorAttempt $attempt, private BackupCodeCheck $check)
    {
    }

    public function __invoke(UseBackupCode $command): void
    {
        $this->attempt->run($command, $this->check);
    }
}
