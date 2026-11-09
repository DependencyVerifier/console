<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

use Shared\Application\Bus\Command;

/**
 * Every command is auditable: the audit middlewares write one entry per command (success inside the transaction,
 * failure or refusal after the rollback). The scope is resolved from the real target, never sent by the client.
 */
interface AuditableCommand extends Command
{
    public function auditAction(): AuditAction;

    public function auditTarget(): Target;

    public function auditContext(): AuditContext;
}
