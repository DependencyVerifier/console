<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Name and quotas of a team, in one gesture of the super admin (the team edit dialog of /admin/teams). Lowering a
 * quota under the usage deletes nothing but blocks new creations. A TEAM_ADMIN renames through RenameTeam.
 */
final readonly class UpdateTeam implements AuditableCommand
{
    /**
     * @param array<string, ?int> $quotas keyed by QuotaName value (null: unlimited)
     */
    public function __construct(
        public string $teamId,
        public string $name,
        public array $quotas,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.update_team');
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
