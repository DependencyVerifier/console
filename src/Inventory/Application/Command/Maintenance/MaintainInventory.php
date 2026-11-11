<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Maintenance;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * `make inventory-maintain` (cron, hourly): inventories left open an hour are discarded, the history of each team is
 * kept snapshotRetentionDays (the latest entry of each subject always), and the snapshots nothing references any
 * more are deleted with their packages.
 */
final readonly class MaintainInventory implements AuditableCommand
{
    public function __construct(public MaintenanceReport $report = new MaintenanceReport(), public int $batch = 500)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('inventory.maintain');
    }

    public function auditTarget(): Target
    {
        return Target::none();
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
