<?php

declare(strict_types=1);

namespace Fleet\UI\Command;

use Fleet\Application\Command\Release\ImportAgentRelease;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ImportOutcome
{
    public static function of(SymfonyStyle $io, CommandBus $commands, ImportAgentRelease $command): int
    {
        try {
            $commands->dispatch($command);
        } catch (AlreadyAudited $refused) {
            $io->error('Release refused: '.$refused->cause()->getMessage());

            return 1;
        }

        $io->success('Release verified and stored.');

        return 0;
    }
}
