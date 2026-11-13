<?php

declare(strict_types=1);

namespace Fleet\UI\Command;

use Fleet\Application\Command\Release\ImportAgentRelease;
use Fleet\Application\Release\AgentRelease;
use Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A release imported by hand, for a console without Internet (`make agent-release-import dir=…`): the folder holds
 * `SHA256SUMS`, `SHA256SUMS.minisig` and the binaries, as the project publishes them.
 */
#[AsCommand(name: 'app:fleet:import-agent-release', description: 'Import a signed release of the agent from a folder')]
final readonly class ImportAgentReleaseCommand
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('The folder of the release')] string $dir): int
    {
        $read = static fn (string $name): ?string => is_file($dir.'/'.$name) ? (string) file_get_contents($dir.'/'.$name) : null;
        $binaries = [];
        foreach (AgentRelease::ARCHITECTURES as $architecture) {
            $name = AgentRelease::fileOf($architecture);
            if (null !== $bytes = $read($name)) {
                $binaries[$name] = $bytes;
            }
        }

        return ImportOutcome::of($io, $this->commands, new ImportAgentRelease($read('SHA256SUMS') ?? '', $read('SHA256SUMS.minisig') ?? '', $binaries));
    }
}
