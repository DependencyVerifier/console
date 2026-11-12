<?php

declare(strict_types=1);

namespace Exposure\Application\Command\Risk;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Accepts the risk of a finding until a date (`Y-m-d`), with a justification (not audited: free text); a running
 * acceptance is replaced.
 */
final readonly class AcceptRisk implements AuditableCommand
{
    public function __construct(public string $findingId, public string $justification, public string $expiresOn)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('exposure.accept_risk', ['expires_on']);
    }

    public function auditTarget(): Target
    {
        return Target::of('finding', $this->findingId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['expires_on' => $this->expiresOn]);
    }
}
