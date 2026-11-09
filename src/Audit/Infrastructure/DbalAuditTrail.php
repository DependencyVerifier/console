<?php

declare(strict_types=1);

namespace Audit\Infrastructure;

use Doctrine\DBAL\Connection;
use Shared\Application\Audit\AuditEntry;
use Shared\Application\Audit\AuditTrail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only writer (plain INSERT, no ORM entity). Runs on the default connection: inside the command transaction
 * for a success, after the rollback for a failure (see the command bus middlewares).
 */
final readonly class DbalAuditTrail implements AuditTrail
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function record(AuditEntry $entry): void
    {
        $this->connection->insert('audit.audit_log', [
            'id' => Uuid::v7()->toRfc4122(),
            'occurred_at' => $entry->occurredAt->format('Y-m-d H:i:s.uP'),
            'actor_type' => $entry->actor->type->value,
            'actor_id' => $entry->actor->idForStorage(),
            'action' => $entry->action->name,
            'target_type' => $entry->target->type,
            'target_id' => $entry->target->idForStorage(),
            'team_id' => $entry->scope->teamIdForStorage(),
            'project_id' => $entry->scope->projectIdForStorage(),
            'outcome' => $entry->outcome->kind->value,
            'reason' => $entry->outcome->reason,
            'ip' => $entry->request->ip,
            'user_agent' => $entry->request->userAgent,
            'request_id' => $entry->request->requestId,
            'context' => $entry->context->toJson(),
        ]);
    }
}
