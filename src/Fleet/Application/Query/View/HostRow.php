<?php

declare(strict_types=1);

namespace Fleet\Application\Query\View;

final readonly class HostRow
{
    public function __construct(
        public string $id,
        public string $hostname,
        public ?string $os,
        public ?string $arch,
        public int $liveAgents,
        public int $agents,
        public ?\DateTimeImmutable $lastSeenAt,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
