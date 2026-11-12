<?php

declare(strict_types=1);

namespace Exposure\Application\Query\View;

/**
 * A finding as the lists show it; `acceptedUntil` is the expiry of its running acceptance.
 */
final readonly class FindingRow
{
    /**
     * @param list<string> $aliases
     */
    public function __construct(
        public string $id,
        public string $teamId,
        public string $subjectType,
        public string $subjectId,
        public string $kind,
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
        public string $status,
        public \DateTimeImmutable $firstSeenAt,
        public \DateTimeImmutable $lastSeenAt,
        public ?\DateTimeImmutable $fixedAt,
        public ?\DateTimeImmutable $acceptedUntil,
    ) {
    }

    public function isAccepted(): bool
    {
        return 'open' === $this->status && null !== $this->acceptedUntil;
    }
}
