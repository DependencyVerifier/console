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
    ) {
    }

    public function can(string $permission): bool
    {
        return $this->can[$permission] ?? false;
    }
}
