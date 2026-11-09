<?php

declare(strict_types=1);

namespace Organization\Application\Command\Project;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Creates a project in a team (quota maxProjects); the creator becomes PROJECT_ADMIN unless TEAM_ADMIN. Audited
 * on the team (a refused creation has no project).
 */
final readonly class CreateProject implements AuditableCommand
{
    public function __construct(
        public string $projectId,
        public string $teamId,
        public string $name,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.create_project', ['project_id']);
    }

    public function auditTarget(): Target
    {
        return Target::of('team', $this->teamId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['project_id' => $this->projectId]);
    }
}
