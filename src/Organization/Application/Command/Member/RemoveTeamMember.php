<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Removes a member from the team and from every project of the team.
 */
final readonly class RemoveTeamMember implements AuditableCommand
{
    public function __construct(
        public string $teamId,
        public string $userId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.remove_team_member', ['user_id']);
    }

    public function auditTarget(): Target
    {
        return Target::of('team', $this->teamId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['user_id' => $this->userId]);
    }
}
