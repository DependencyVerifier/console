<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Maintenance;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * `make fleet-maintain` (cron, every few minutes): approved agents silent for 7 days become inactive, inactive ones
 * silent 30 days more are revoked, delivered orders without result expire, nonces older than 10 minutes go.
 */
final readonly class MaintainFleet implements AuditableCommand
{
    public function __construct(public MaintenanceReport $report = new MaintenanceReport(), public int $batch = 500)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.maintain');
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
