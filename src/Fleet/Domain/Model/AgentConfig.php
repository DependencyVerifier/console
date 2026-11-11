<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use function Symfony\Component\String\u;

/**
 * The collection settings of a SET_CONFIG order (protocol §7.3): interval 5–10 080 minutes (never under the team's
 * minScanIntervalMinutes, checked by the handler), absolute paths, at most 50 of each.
 */
final readonly class AgentConfig
{
    public const int MIN_INTERVAL = 5;
    public const int MAX_INTERVAL = 10080;
    private const int MAX_PATHS = 50;

    /**
     * @param list<string> $discoveryRoots
     * @param list<string> $declaredPaths
     * @param list<string> $exclusions     glob patterns
     */
    public function __construct(
        public int $scanIntervalMinutes,
        public array $discoveryRoots = [],
        public array $declaredPaths = [],
        public array $exclusions = [],
        public int $version = 0,
    ) {
        if ($scanIntervalMinutes < self::MIN_INTERVAL || $scanIntervalMinutes > self::MAX_INTERVAL || $version < 0) {
            throw new InvalidOrderPayload();
        }

        foreach ([$discoveryRoots, $declaredPaths, $exclusions] as $paths) {
            if (\count($paths) > self::MAX_PATHS) {
                throw new InvalidOrderPayload();
            }

            foreach ($paths as $path) {
                if (!u($path)->startsWith('/') || u($path)->length() > 1024 || u($path)->containsAny("\0")) {
                    throw new InvalidOrderPayload();
                }
            }
        }
    }

    public function withVersion(int $version): self
    {
        return new self($this->scanIntervalMinutes, $this->discoveryRoots, $this->declaredPaths, $this->exclusions, $version);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $interval = $payload['scan_interval_minutes'] ?? null;
        $version = $payload['config_version'] ?? null;
        if (!\is_int($interval) || !\is_int($version)) {
            throw new InvalidOrderPayload();
        }

        return new self($interval, self::paths($payload['discovery_roots'] ?? []), self::paths($payload['declared_paths'] ?? []), self::paths($payload['exclusions'] ?? []), $version);
    }

    /**
     * @return array{config_version: int, scan_interval_minutes: int, discovery_roots: list<string>, declared_paths: list<string>, exclusions: list<string>}
     */
    public function toPayload(): array
    {
        return [
            'config_version' => $this->version,
            'scan_interval_minutes' => $this->scanIntervalMinutes,
            'discovery_roots' => $this->discoveryRoots,
            'declared_paths' => $this->declaredPaths,
            'exclusions' => $this->exclusions,
        ];
    }

    /**
     * @return list<string>
     */
    private static function paths(mixed $paths): array
    {
        if (!\is_array($paths) || !array_is_list($paths)) {
            throw new InvalidOrderPayload();
        }

        $result = [];
        foreach ($paths as $path) {
            if (!\is_string($path)) {
                throw new InvalidOrderPayload();
            }

            $result[] = $path;
        }

        return $result;
    }
}
