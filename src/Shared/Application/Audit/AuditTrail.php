<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

/**
 * Writes the append-only audit log (implemented by the Audit context).
 */
interface AuditTrail
{
    public function record(AuditEntry $entry): void;
}
