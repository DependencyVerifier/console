<?php

declare(strict_types=1);

namespace Organization\Application\Query\View;

final readonly class ProjectView
{
    /**
     * @param array<string, bool> $can
     */
    public function __construct(
        public string $id,
        public string $teamId,
        public string $teamName,
        public string $name,
        public int $environments,
        public ?int $environmentQuota,
        public array $can,
        public string $slug,
        public int $memberCount,
        public \DateTimeImmutable $createdAt,
        public bool $memberQuotaReached,
    ) {
    }

    /**
     * The team's limit of environments per project is reached: "Add environment" is disabled (CreateEnvironment
     * refuses anyway).
     */
    public function environmentQuotaReached(): bool
    {
        return null !== $this->environmentQuota && $this->environments >= $this->environmentQuota;
    }

    public function can(string $permission): bool
    {
        return $this->can[$permission] ?? false;
    }
}
