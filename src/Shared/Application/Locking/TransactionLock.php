<?php

declare(strict_types=1);

namespace Shared\Application\Locking;

/**
 * Exclusive lock held until the end of the command transaction (pg_advisory_xact_lock), for invariants without a
 * row to lock: the last active super admin, an account being created for an email.
 */
interface TransactionLock
{
    public function acquire(string $key): void;
}
