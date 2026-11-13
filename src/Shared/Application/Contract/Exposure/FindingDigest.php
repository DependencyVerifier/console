<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Exposure;

/**
 * A finding as a notification shows it.
 */
final readonly class FindingDigest
{
    /**
     * @param list<string> $aliases
     */
    public function __construct(
        public string $id,
        public string $teamId,
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
        public ?string $suggested,
        public ?string $latest,
        public array $aliases,
        public string $status,
        public bool $accepted,
        public string $subjectType,
        public string $subjectId,
        public string $subjectName,
        public ?string $projectId,
        public ?string $environmentId,
    ) {
    }
}
