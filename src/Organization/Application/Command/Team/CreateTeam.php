<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Creates a team (default quotas copied) and invites its first TEAM_ADMIN; the team is tolerated without admin until the invitation is accepted.
 */
final readonly class CreateTeam implements AuditableCommand
{
    public function __construct(
        public string $teamId,
        public string $name,
        public string $firstAdminEmail,
        public string $invitationId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.create_team');
    }

    public function auditTarget(): Target
    {
        return Target::of('team', $this->teamId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
