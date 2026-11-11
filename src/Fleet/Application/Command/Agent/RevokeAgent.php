<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Agent;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Revokes an agent (refusing a pending one included): it never comes back, its open orders are cancelled.
 */
final readonly class RevokeAgent implements AuditableCommand
{
    public function __construct(public string $agentId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.revoke_agent');
    }

    public function auditTarget(): Target
    {
        return Target::of('agent', $this->agentId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
