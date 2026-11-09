<?php

declare(strict_types=1);

namespace Organization\Application\Command\Project;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Renames a project.
 */
final readonly class RenameProject implements AuditableCommand
{
    public function __construct(
        public string $projectId,
        public string $name,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.rename_project');
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
