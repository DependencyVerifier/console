<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Application;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A human attaches an application to an environment of its team (project.apps.assign on that project): the automatic
 * rules never change it afterwards.
 */
final readonly class AttachApplication implements AuditableCommand
{
    public function __construct(public string $appId, public string $environmentId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('inventory.attach_application', ['environment_id']);
    }

    public function auditTarget(): Target
    {
        return Target::of('application', $this->appId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['environment_id' => $this->environmentId]);
    }
}
