<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Release\Handler;

use Fleet\Application\Command\Release\ImportAgentRelease;
use Fleet\Application\Port\AgentReleaseStore;
use Fleet\Application\Release\AgentRelease;
use Fleet\Application\Release\Minisign;
use Fleet\Domain\Model\InvalidAgentRelease;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * The first link of the install trust chain (cadrage §6): the checksums signed by the project release key (its
 * trusted comment names the version), then each binary checked against them.
 */
final readonly class ImportAgentReleaseHandler implements CommandHandler
{
    public function __construct(
        private AgentReleaseStore $store,
        #[Autowire(env: 'AGENT_RELEASE_PUBKEY')]
        private string $releaseKey,
    ) {
    }

    public function __invoke(ImportAgentRelease $command): void
    {
        if ('' === u($this->releaseKey)->trim()->toString()) {
            throw new InvalidAgentRelease('AGENT_RELEASE_PUBKEY is not set: no release can be trusted.');
        }

        $comment = Minisign::verify($this->releaseKey, $command->checksums, $command->signature);
        $version = u($comment)->match('/^depverify-agent v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/')[1] ?? null;
        if (!\is_string($version)) {
            throw new InvalidAgentRelease('The signed comment names no version.');
        }

        $sums = [];
        foreach (u($command->checksums)->split("\n") as $line) {
            $match = $line->trim()->match('/^([0-9a-f]{64})\s+\*?(\S+)$/');
            if (\is_string($match[1] ?? null) && \is_string($match[2] ?? null)) {
                $sums[$match[2]] = $match[1];
            }
        }

        $binaries = [];
        $kept = [];
        foreach (AgentRelease::ARCHITECTURES as $architecture) {
            $name = AgentRelease::fileOf($architecture);
            if (!isset($sums[$name], $command->binaries[$name])) {
                continue;
            }

            if (!hash_equals($sums[$name], hash('sha256', $command->binaries[$name]))) {
                throw new InvalidAgentRelease(\sprintf('%s does not match its signed checksum.', $name));
            }

            $binaries[$architecture] = ['name' => $name, 'sha256' => $sums[$name], 'size' => b($command->binaries[$name])->length()];
            $kept[$name] = $command->binaries[$name];
        }

        if ([] === $binaries) {
            throw new InvalidAgentRelease('No binary of a supported architecture.');
        }

        $this->store->save(new AgentRelease($version, $binaries), $kept);
    }
}
