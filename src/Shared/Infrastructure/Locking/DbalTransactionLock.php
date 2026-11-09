<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Locking;

use Doctrine\DBAL\Connection;
use Shared\Application\Locking\TransactionLock;

/**
 * pg_advisory_xact_lock on the default connection, so it belongs to the command transaction (bounded by the
 * transaction's lock_timeout, LockTimeoutMiddleware) and is released by its commit or rollback.
 */
final readonly class DbalTransactionLock implements TransactionLock
{
    public function __construct(private Connection $connection)
    {
    }

    public function acquire(string $key): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A transaction lock needs an open transaction (dispatch through the command bus).');
        }

        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $key]);
    }
}
