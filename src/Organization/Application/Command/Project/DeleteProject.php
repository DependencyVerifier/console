<?php

declare(strict_types=1);

namespace Organization\Application\Command\Project;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Deletes an empty project (no environment, no content declared by a context); its pending invitations are revoked.
 */
final readonly class DeleteProject implements AuditableCommand
{
    public function __construct(
        public string $projectId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.delete_project');
    }

    public function auditTarget(): Target
    {
        return Target::of('project', $this->projectId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
