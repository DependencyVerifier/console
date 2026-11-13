<?php

declare(strict_types=1);

namespace Fleet\Application\Port;

use Fleet\Application\Release\AgentRelease;

/**
 * The verified releases of the agent the console serves (install.sh and its downloads).
 */
interface AgentReleaseStore
{
    /**
     * @param array<string, string> $binaries file name => bytes, already verified
     */
    public function save(AgentRelease $release, array $binaries): void;

    /**
     * The highest version stored.
     */
    public function latest(): ?AgentRelease;

    /**
     * The local path of a binary of a stored release, none for anything else.
     */
    public function path(string $version, string $name): ?string;
}
