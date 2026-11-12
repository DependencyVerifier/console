<?php

declare(strict_types=1);

namespace Exposure\Application\Command\Refresh;

use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * The findings of a team (of every inventoried team when none) are to be computed again. Sent by every inventory and
 * every changed advisory, so it writes no audit entry (the computation does, once).
 */
final readonly class RequestExposureRefresh implements SelfAuditedCommand
{
    public function __construct(public ?string $teamId = null)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('exposure.request_refresh');
    }

    public function auditTarget(): Target
    {
        return null === $this->teamId ? Target::none() : Target::of('team', $this->teamId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
