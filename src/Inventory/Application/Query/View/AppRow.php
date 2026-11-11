<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

final readonly class AppRow
{
    /**
     * @param array<string, mixed> $fields the subject's fields (path, runtime, service, labels…)
     */
    public function __construct(
        public string $id,
        public string $teamId,
        public string $hostId,
        public string $hostname,
        public string $subjectKey,
        public string $kind,
        public array $fields,
        public ?string $imageId,
        public ?string $environmentId,
        public string $assignment,
        public bool $present,
        public \DateTimeImmutable $lastSeenAt,
        public string $sbomDigest,
        public int $packages,
        public string $environment = '',
    ) {
    }

    /**
     * What a human calls it: its label name, its Compose service, its path, its container.
     */
    public function name(): string
    {
        $labels = \is_array($this->fields['labels'] ?? null) ? $this->fields['labels'] : [];
        foreach ([$labels['name'] ?? null, isset($this->fields['service'], $this->fields['compose_project']) && \is_string($this->fields['service']) && \is_string($this->fields['compose_project']) ? $this->fields['compose_project'].' / '.$this->fields['service'] : null, $this->fields['path'] ?? null, $this->fields['container_name'] ?? null] as $name) {
            if (\is_string($name) && '' !== $name) {
                return $name;
            }
        }

        return $this->subjectKey;
    }

    public function runtime(): ?string
    {
        $runtime = \is_array($this->fields['runtime'] ?? null) ? $this->fields['runtime'] : [];
        $name = $runtime['name'] ?? null;
        $version = $runtime['version'] ?? null;

        return \is_string($name) && \is_string($version) ? $name.' '.$version : null;
    }

    public function withEnvironment(string $environment): self
    {
        return new self($this->id, $this->teamId, $this->hostId, $this->hostname, $this->subjectKey, $this->kind, $this->fields, $this->imageId, $this->environmentId, $this->assignment, $this->present, $this->lastSeenAt, $this->sbomDigest, $this->packages, $environment);
    }
}
