<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Changes the team role of a member (a role lower than or equal to the actor's own; the team keeps a TEAM_ADMIN).
 */
final readonly class ChangeTeamRole implements AuditableCommand
{
    public function __construct(
        public string $teamId,
        public string $userId,
        public string $role,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.change_team_role', ['user_id', 'role']);
    }

    public function auditTarget(): Target
    {
        return Target::of('team', $this->teamId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['user_id' => $this->userId, 'role' => $this->role]);
    }
}
