<?php

declare(strict_types=1);

namespace Fleet\Application\Query\View;

final readonly class AgentRow
{
    public function __construct(
        public string $id,
        public string $teamId,
        public string $status,
        public string $fingerprint,
        public string $mode,
        public ?string $scope,
        public ?string $composeProject,
        public string $version,
        public string $hostId,
        public string $hostname,
        public \DateTimeImmutable $enrolledAt,
        public \DateTimeImmutable $lastSeenAt,
        public string $teamName = '',
    ) {
    }
}
