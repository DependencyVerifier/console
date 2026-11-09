<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Changes the project role of a member.
 */
final readonly class ChangeProjectRole implements AuditableCommand
{
    public function __construct(
        public string $projectId,
        public string $userId,
        public string $role,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.change_project_role', ['user_id', 'role']);
    }

    public function auditTarget(): Target
    {
        return Target::of('project', $this->projectId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['user_id' => $this->userId, 'role' => $this->role]);
    }
}
