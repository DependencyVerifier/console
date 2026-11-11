<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Order;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * "Scan now": a SCAN_NOW order for an approved agent, one every 5 minutes at most (protocol §10).
 */
final readonly class RequestScan implements AuditableCommand
{
    public function __construct(public string $orderId, public string $agentId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.request_scan', ['order_id']);
    }

    public function auditTarget(): Target
    {
        return Target::of('agent', $this->agentId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['order_id' => $this->orderId]);
    }
}
