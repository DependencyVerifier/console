<?php

declare(strict_types=1);

namespace Audit\Application\Query\AuditLog;

final readonly class AuditEntryView
{
    /**
     * @param array<string, string|int|bool> $context
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $occurredAt,
        public string $actorType,
        public ?string $actorId,
        public string $action,
        public string $targetType,
        public ?string $targetId,
        public ?string $teamId,
        public string $outcome,
        public string $reason,
        public ?string $ip,
        public string $requestId,
        public array $context,
    ) {
    }
}
