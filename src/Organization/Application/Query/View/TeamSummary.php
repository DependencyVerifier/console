<?php

declare(strict_types=1);

namespace Organization\Application\Query\View;

final readonly class TeamSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $role,
        public int $members,
        public int $projects,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
