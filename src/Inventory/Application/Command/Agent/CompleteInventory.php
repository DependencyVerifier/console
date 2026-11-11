<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Agent;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * POST /agent/v1/inventories/{id}/complete (protocol §8.4): every snapshot is stored, the processing is queued.
 */
final readonly class CompleteInventory implements AuditableCommand
{
    public function __construct(public string $agentId, public string $inventoryId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('inventory.complete');
    }

    public function auditTarget(): Target
    {
        return Target::of('inventory', $this->inventoryId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
