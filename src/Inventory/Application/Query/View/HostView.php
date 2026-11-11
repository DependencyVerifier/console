<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

final readonly class HostView
{
    /**
     * @param array<string, mixed> $facts the last host facts (os, kernel, arch, runtimes, docker, agent)
     */
    public function __construct(
        public string $hostId,
        public string $teamId,
        public string $hostname,
        public array $facts,
        public ?string $packagesDigest,
        public ?\DateTimeImmutable $inventoriedAt,
        public int $packages,
    ) {
    }

    public function os(): ?string
    {
        $os = \is_array($this->facts['os'] ?? null) ? $this->facts['os'] : [];

        return \is_string($os['pretty_name'] ?? null) ? $os['pretty_name'] : null;
    }

    /**
     * @return list<array{name: string, version: string, service: ?string}>
     */
    public function runtimes(): array
    {
        $runtimes = [];
        foreach (\is_array($this->facts['runtimes'] ?? null) ? $this->facts['runtimes'] : [] as $runtime) {
            if (\is_array($runtime) && \is_string($runtime['name'] ?? null) && \is_string($runtime['version'] ?? null)) {
                $runtimes[] = ['name' => $runtime['name'], 'version' => $runtime['version'], 'service' => \is_string($runtime['service'] ?? null) ? $runtime['service'] : null];
            }
        }

        return $runtimes;
    }

    public function fact(string $name): ?string
    {
        return \is_string($this->facts[$name] ?? null) ? $this->facts[$name] : null;
    }

    public function dockerVersion(): ?string
    {
        $docker = \is_array($this->facts['docker'] ?? null) ? $this->facts['docker'] : [];

        return \is_string($docker['engine_version'] ?? null) ? $docker['engine_version'] : null;
    }
}
