<?php

declare(strict_types=1);

namespace Exposure\Domain\Model;

/**
 * What the matching says of a finding today: the identity within its subject (kind, key) and what may change from
 * one computation to the next (versions, severity, exploitation signals, end of life level).
 */
final readonly class FindingFacts
{
    /**
     * @param list<string> $aliases
     */
    public function __construct(
        public FindingKind $kind,
        public string $key,
        public string $title,
        public string $package,
        public ?string $installed,
        public ?string $fixed,
        public ?string $severity,
        public ?float $cvss,
        public bool $knownExploited,
        public ?float $epss,
        public ?string $eolLevel,
        public ?\DateTimeImmutable $endOfLife,
        public ?string $latest,
        public array $aliases,
    ) {
    }
}
