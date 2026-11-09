<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Audit;

use Shared\Application\Audit\AuditTrail;
use Shared\Application\Audit\CommandAudit;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\SelfAuditedCommand;

final readonly class TrailCommandAudit implements CommandAudit
{
    public function __construct(private AuditTrail $auditTrail, private AuditEntryFactory $entries)
    {
    }

    public function record(SelfAuditedCommand $command, Outcome $outcome): void
    {
        $this->auditTrail->record($this->entries->create(
            $command->auditAction(),
            $command->auditTarget(),
            $outcome,
            $command->auditContext(),
        ));
    }
}
