<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Release;

use Fleet\Application\Port\AgentReleaseStore;
use Fleet\Application\Release\AgentRelease;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `<dir>/<version>/manifest.json` and the binaries next to it. The directory holds only verified releases.
 */
final readonly class FilesystemAgentReleaseStore implements AgentReleaseStore
{
    public function __construct(
        #[Autowire('%env(default:agent_releases_default_dir:AGENT_RELEASES_DIR)%')]
        private string $directory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function save(AgentRelease $release, array $binaries): void
    {
        $dir = $this->directory.'/'.$release->version;
        $this->filesystem->mkdir($dir, 0o755);
        foreach ($binaries as $name => $bytes) {
            $this->filesystem->dumpFile($dir.'/'.$name, $bytes);
        }

        $this->filesystem->dumpFile($dir.'/manifest.json', json_encode(['version' => $release->version, 'binaries' => $release->binaries], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
    }

    public function latest(): ?AgentRelease
    {
        $latest = null;
        foreach (glob($this->directory.'/*/manifest.json') ?: [] as $file) {
            $release = $this->read($file);
            if (null !== $release && (null === $latest || version_compare($release->version, $latest->version, '>'))) {
                $latest = $release;
            }
        }

        return $latest;
    }

    public function path(string $version, string $name): ?string
    {
        $release = $this->read($this->directory.'/'.basename($version).'/manifest.json');
        if (null === $release || $release->version !== $version) {
            return null;
        }

        foreach ($release->binaries as $binary) {
            if ($binary['name'] === $name) {
                return $this->directory.'/'.$version.'/'.$name;
            }
        }

        return null;
    }

    private function read(string $file): ?AgentRelease
    {
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!\is_array($json) || !\is_string($json['version'] ?? null) || !\is_array($json['binaries'] ?? null)) {
            return null;
        }

        $binaries = [];
        foreach ($json['binaries'] as $architecture => $binary) {
            if (\is_string($architecture) && \is_array($binary) && \is_string($binary['name'] ?? null) && \is_string($binary['sha256'] ?? null) && \is_int($binary['size'] ?? null)) {
                $binaries[$architecture] = ['name' => $binary['name'], 'sha256' => $binary['sha256'], 'size' => $binary['size']];
            }
        }

        return new AgentRelease($json['version'], $binaries);
    }
}
