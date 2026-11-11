<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Application;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A human detaches an application from its environment (project.apps.assign on its project): the automatic rules
 * never attach it again.
 */
final readonly class DetachApplication implements AuditableCommand
{
    public function __construct(public string $appId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('inventory.detach_application');
    }

    public function auditTarget(): Target
    {
        return Target::of('application', $this->appId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
