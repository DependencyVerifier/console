<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Application\Port\AgentReleaseStore;
use Fleet\Application\Release\AgentRelease;

/**
 * The release store with a fixed latest release (none by default).
 */
final class FixedReleases implements AgentReleaseStore
{
    public function __construct(public ?AgentRelease $latest = null)
    {
    }

    public function save(AgentRelease $release, array $binaries): void
    {
        $this->latest = $release;
    }

    public function latest(): ?AgentRelease
    {
        return $this->latest;
    }

    public function path(string $version, string $name): ?string
    {
        return null;
    }
}
