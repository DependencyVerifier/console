<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Order;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * "Rotate key": a ROTATE_KEY order for an approved agent (host.order), one waiting at a time.
 */
final readonly class RotateAgentKey implements AuditableCommand
{
    public function __construct(public string $agentId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.request_key_rotation');
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
