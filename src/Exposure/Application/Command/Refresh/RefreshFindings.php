<?php

declare(strict_types=1);

namespace Exposure\Application\Command\Refresh;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The findings of a team computed again from what it runs (queue `matching`): new ones open, those no longer
 * produced are fixed, those coming back open again. Idempotent.
 */
final readonly class RefreshFindings implements AuditableCommand
{
    public function __construct(public string $teamId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('exposure.refresh_findings');
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
