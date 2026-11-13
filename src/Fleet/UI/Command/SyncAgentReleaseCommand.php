<?php

declare(strict_types=1);

namespace Fleet\UI\Command;

use Fleet\Application\Command\Release\ImportAgentRelease;
use Fleet\Application\Release\AgentRelease;
use Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Run it from cron, daily (`make agent-release-sync`): the latest GitHub release of the agent, verified with the
 * project release key before anything is served.
 */
#[AsCommand(name: 'app:fleet:sync-agent-release', description: 'Download, verify and store the latest release of the agent')]
final readonly class SyncAgentReleaseCommand
{
    private const int MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private CommandBus $commands,
        private HttpClientInterface $http,
        #[Autowire('%env(default:agent_releases_default_url:AGENT_RELEASES_URL)%')]
        private string $releasesUrl,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $release = $this->http->request('GET', $this->releasesUrl, ['headers' => ['Accept' => 'application/vnd.github+json'], 'timeout' => 30])->toArray();
        $assets = [];
        foreach (\is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            if (\is_array($asset) && \is_string($asset['name'] ?? null) && \is_string($asset['browser_download_url'] ?? null)) {
                $assets[$asset['name']] = $asset['browser_download_url'];
            }
        }

        $download = function (string $name) use ($assets): ?string {
            if (!isset($assets[$name]) || !str_starts_with($assets[$name], 'https://')) {
                return null;
            }

            $content = $this->http->request('GET', $assets[$name], ['timeout' => 120, 'max_duration' => 600])->getContent();

            return \strlen($content) > self::MAX_BYTES ? null : $content;
        };
        $binaries = [];
        foreach (AgentRelease::ARCHITECTURES as $architecture) {
            $name = AgentRelease::fileOf($architecture);
            if (null !== $bytes = $download($name)) {
                $binaries[$name] = $bytes;
            }
        }

        return ImportOutcome::of($io, $this->commands, new ImportAgentRelease($download('SHA256SUMS') ?? '', $download('SHA256SUMS.minisig') ?? '', $binaries));
    }
}
