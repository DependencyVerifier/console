<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Deletes a team with everything it holds (super admin): projects, environments, memberships; its pending
 * invitations are revoked, the grant history and the audit log stay.
 */
final readonly class DeleteTeam implements AuditableCommand
{
    public function __construct(
        public string $teamId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.delete_team');
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
