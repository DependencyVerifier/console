<?php

declare(strict_types=1);

namespace Fleet\UI\Command;

use Fleet\Application\Command\Order\RotateConsoleKey;
use Fleet\Application\Query\Admin\GetConsoleKeyRotation;
use Fleet\Domain\Model\ConsoleKeyRotationNotPrepared;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 2 of the console key rotation (docs/cadrage.md decision 79): with APP_AGENT_SIGNING_KEY_NEXT set, every
 * approved agent receives the next key in an order signed by the key it pins; run again until all of them are on it.
 */
#[AsCommand(name: 'app:fleet:rotate-console-key', description: 'Move the approved agents to the next console key (APP_AGENT_SIGNING_KEY_NEXT)')]
final readonly class RotateConsoleKeyCommand
{
    public function __construct(private CommandBus $commands, private QueryBus $queries)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $this->commands->dispatch(new RotateConsoleKey());
        } catch (ConsoleKeyRotationNotPrepared) {
            $io->error('Set APP_AGENT_SIGNING_KEY_NEXT to a new seed (openssl rand -base64 32) first.');

            return 1;
        }

        $rotation = $this->queries->ask(new GetConsoleKeyRotation());
        $io->text(\sprintf('Current key %s, next key %s.', $rotation->current, $rotation->next ?? '—'));
        if ($rotation->isComplete()) {
            $io->success(\sprintf('%d of %d approved agents pin the next key: make it APP_AGENT_SIGNING_KEY, then empty APP_AGENT_SIGNING_KEY_NEXT.', $rotation->onNext, $rotation->approved));
        } else {
            $io->warning(\sprintf('%d of %d approved agents pin the next key; the others receive it at their next poll. Run this again later.', $rotation->onNext, $rotation->approved));
        }

        return 0;
    }
}
