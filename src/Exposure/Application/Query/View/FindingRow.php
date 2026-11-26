<?php

declare(strict_types=1);

namespace Exposure\Application\Query\View;

use Exposure\Domain\Model\FindingKind;

/**
 * A finding as the lists show it; `acceptedUntil` is the expiry of its running acceptance; where its subject is (its
 * host, `Project / environment` once named by the handler, whether it is in production).
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
        public string $subjectName = '',
        public ?string $suggested = null,
        public ?string $projectId = null,
        public ?string $environmentId = null,
        public ?string $hostId = null,
        public ?string $hostName = null,
        public bool $production = false,
        public string $environment = '',
    ) {
    }

    public function withEnvironment(string $environment): self
    {
        return new self(
            $this->id, $this->teamId, $this->subjectType, $this->subjectId, $this->kind, $this->title, $this->package, $this->installed, $this->fixed, $this->severity,
            $this->cvss, $this->knownExploited, $this->epss, $this->eolLevel, $this->endOfLife, $this->latest, $this->aliases, $this->status, $this->firstSeenAt,
            $this->lastSeenAt, $this->fixedAt, $this->acceptedUntil, $this->subjectName, $this->suggested, $this->projectId, $this->environmentId, $this->hostId,
            $this->hostName, $this->production, $environment,
        );
    }

    public function isAccepted(): bool
    {
        return 'open' === $this->status && null !== $this->acceptedUntil;
    }

    /**
     * Where to go from the installed version: the fixed version of a vulnerability, the latest release of an outdated
     * dependency, the newest supported cycle for an end of life (never the last patch of a cycle that ends). Null when
     * there is none: no fix yet, or no newer supported cycle (the installed one is the newest, or none is supported).
     */
    public function target(): ?string
    {
        return match (FindingKind::tryFrom($this->kind)) {
            FindingKind::Vulnerability => $this->fixed,
            FindingKind::Outdated => $this->latest,
            FindingKind::EndOfLife => $this->suggested,
            null => null,
        };
    }
}
