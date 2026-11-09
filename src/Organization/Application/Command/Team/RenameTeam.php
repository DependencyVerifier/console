<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Renames a team (the slug stays: ids identify).
 */
final readonly class RenameTeam implements AuditableCommand
{
    public function __construct(
        public string $teamId,
        public string $name,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.rename_team');
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
