<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Removes a member from a project (a TEAM_GUEST without project stays in the team).
 */
final readonly class RemoveProjectMember implements AuditableCommand
{
    public function __construct(
        public string $projectId,
        public string $userId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.remove_project_member', ['user_id']);
    }

    public function auditTarget(): Target
    {
        return Target::of('project', $this->projectId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['user_id' => $this->userId]);
    }
}
