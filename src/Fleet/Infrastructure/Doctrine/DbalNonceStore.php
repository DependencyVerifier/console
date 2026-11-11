<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Fleet\Application\Port\NonceStore;

/**
 * `fleet_agent_nonce`, primary key (key_id, nonce): a concurrent replay waits for the first insert, then conflicts.
 * Rows older than 10 minutes are purged by the Fleet maintenance.
 */
final readonly class DbalNonceStore implements NonceStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function remember(string $keyId, string $nonce, \DateTimeImmutable $now): bool
    {
        return 1 === $this->connection->executeStatement(
            'INSERT INTO fleet_agent_nonce (key_id, nonce, seen_at) VALUES (:key, :nonce, :now) ON CONFLICT DO NOTHING',
            ['key' => $keyId, 'nonce' => $nonce, 'now' => $now->format('Y-m-d H:i:s')],
        );
    }

    public function purgeBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM fleet_agent_nonce WHERE seen_at < :before', ['before' => $before->format('Y-m-d H:i:s')]);
    }
}
