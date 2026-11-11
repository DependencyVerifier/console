<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Host;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Deletes a host once every agent on it is revoked (with those agents and their orders).
 */
final readonly class DeleteHost implements AuditableCommand
{
    public function __construct(public string $hostId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.delete_host');
    }

    public function auditTarget(): Target
    {
        return Target::of('host', $this->hostId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
