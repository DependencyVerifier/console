<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Quotas of one team (super admin). Lowering under the usage deletes nothing but blocks new creations.
 */
final readonly class UpdateTeamQuotas implements AuditableCommand
{
    /**
     * @param array<string, ?int> $quotas keyed by QuotaName value (null: unlimited)
     */
    public function __construct(
        public string $teamId,
        public array $quotas,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.update_team_quotas');
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
