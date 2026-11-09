<?php

declare(strict_types=1);

namespace Tests\Support;

use Doctrine\DBAL\Connection;

final class AuditLog
{
    /**
     * @return list<array{action: string, outcome: string, reason: string, target_type: string, target_id: ?string, team_id: ?string, context: string}>
     */
    public static function entries(Connection $connection, string $actionPrefix): array
    {
        /** @var list<array{action: string, outcome: string, reason: string, target_type: string, target_id: ?string, team_id: ?string, context: string}> */
        return $connection->fetchAllAssociative(
            'SELECT action, outcome, reason, target_type, target_id, team_id, context FROM audit.audit_log WHERE action LIKE :prefix ORDER BY occurred_at, id',
            ['prefix' => $actionPrefix.'%'],
        );
    }
}
