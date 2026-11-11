<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Agent;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A human approves a pending agent, after comparing its fingerprint with the one the installation logged.
 */
final readonly class ApproveAgent implements AuditableCommand
{
    public function __construct(public string $agentId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.approve_agent');
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
