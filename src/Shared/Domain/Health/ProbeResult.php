<?php

declare(strict_types=1);

namespace Shared\Domain\Health;

/**
 * Outcome of one health probe. The failure reason stays internal: it is never exposed publicly.
 */
final readonly class ProbeResult
{
    private function __construct(
        public string $name,
        public bool $healthy,
        public ?string $failureReason,
    ) {
    }

    public static function up(string $name): self
    {
        return new self($name, true, null);
    }

    public static function down(string $name, string $failureReason): self
    {
        return new self($name, false, $failureReason);
    }
}
