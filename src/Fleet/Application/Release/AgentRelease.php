<?php

declare(strict_types=1);

namespace Fleet\Application\Release;

/**
 * A verified release of the agent: its version and, per architecture, its binary (file name, SHA-256, size).
 */
final readonly class AgentRelease
{
    public const array ARCHITECTURES = ['x86_64', 'aarch64'];
    public const string CHECKSUMS = 'SHA256SUMS';
    public const string SIGNATURE = 'SHA256SUMS.minisig';

    /**
     * @param array<string, array{name: string, sha256: string, size: int}> $binaries by architecture
     */
    public function __construct(public string $version, public array $binaries)
    {
    }

    public static function fileOf(string $architecture): string
    {
        return 'depverify-agent-'.$architecture.'-linux';
    }
}
