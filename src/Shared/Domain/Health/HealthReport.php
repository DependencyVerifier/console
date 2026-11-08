<?php

declare(strict_types=1);

namespace Shared\Domain\Health;

final readonly class HealthReport
{
    /**
     * @param list<ProbeResult> $probes
     */
    public function __construct(
        public array $probes,
        public float $durationMs,
    ) {
    }

    public function isHealthy(): bool
    {
        return array_all($this->probes, static fn ($probe) => $probe->healthy);
    }

    /**
     * @return array<string, 'up'|'down'>
     */
    public function summary(): array
    {
        $summary = [];
        foreach ($this->probes as $probe) {
            $summary[$probe->name] = $probe->healthy ? 'up' : 'down';
        }

        return $summary;
    }
}
