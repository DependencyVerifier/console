<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Creates a team (default quotas copied) with its first TEAM_ADMIN, admitted at once: an existing account, or an
 * account invited by Identity for an unknown email (`firstAdminUserId` and `setupRequestId` are used only then).
 */
final readonly class CreateTeam implements AuditableCommand
{
    public function __construct(
        public string $teamId,
        public string $name,
        public string $firstAdminEmail,
        public string $firstAdminUserId,
        public string $setupRequestId,
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
