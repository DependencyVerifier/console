<?php

declare(strict_types=1);

namespace Exposure\Application\Command\Risk;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Ends the running acceptance of a finding: it counts as open again.
 */
final readonly class RevokeRiskAcceptance implements AuditableCommand
{
    public function __construct(public string $findingId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('exposure.revoke_risk_acceptance');
    }

    public function auditTarget(): Target
    {
        return Target::of('finding', $this->findingId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
