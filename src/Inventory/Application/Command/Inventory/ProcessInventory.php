<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Inventory;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Turns a completed inventory into host facts, applications, images and packages. Sent to the `ingest` queue by
 * InventoryCompleted and run by the worker; processing it twice changes nothing.
 */
final readonly class ProcessInventory implements AuditableCommand
{
    public function __construct(public string $inventoryId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('inventory.process');
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
