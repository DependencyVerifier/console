<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

/**
 * The facts of a host an inventory observed, as Fleet takes them (display, and the Docker engine id that may promote
 * the identity of its host). The only reader of the host facts document on the write side; a value absent, empty or
 * not a string is null. Bounds are Fleet's to judge.
 */
final readonly class ObservedHostFacts
{
    public function __construct(
        public ?string $hostname = null,
        public ?string $osId = null,
        public ?string $osVersionId = null,
        public ?string $osPrettyName = null,
        public ?string $kernel = null,
        public ?string $arch = null,
        public ?string $dockerEngineId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $facts the host facts document
     */
    public static function fromDocument(array $facts): self
    {
        $os = \is_array($facts['os'] ?? null) ? $facts['os'] : [];
        $docker = \is_array($facts['docker'] ?? null) ? $facts['docker'] : [];

        return new self(
            hostname: self::text($facts['hostname'] ?? null),
            osId: self::text($os['id'] ?? null),
            osVersionId: self::text($os['version_id'] ?? null),
            osPrettyName: self::text($os['pretty_name'] ?? null),
            kernel: self::text($facts['kernel'] ?? null),
            arch: self::text($facts['arch'] ?? null),
            dockerEngineId: self::text($docker['engine_id'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
