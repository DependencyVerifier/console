<?php

declare(strict_types=1);

namespace Fleet\Application\Query\View;

final readonly class OrderRow
{
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $issuedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?string $reason,
    ) {
    }
}
