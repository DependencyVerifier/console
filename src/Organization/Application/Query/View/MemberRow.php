<?php

declare(strict_types=1);

namespace Organization\Application\Query\View;

final readonly class MemberRow
{
    /**
     * @param list<string> $extras
     */
    public function __construct(
        public string $userId,
        public string $email,
        public string $role,
        public array $extras,
        public bool $active,
        public int $projects,
        public \DateTimeImmutable $joinedAt,
    ) {
    }
}
