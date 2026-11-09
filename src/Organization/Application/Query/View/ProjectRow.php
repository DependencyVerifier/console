<?php

declare(strict_types=1);

namespace Organization\Application\Query\View;

final readonly class ProjectRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public int $environments,
        public int $members,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
