<?php

declare(strict_types=1);

namespace Notification\Application\Command\Notify;

use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * An agent enrolled and waits for approval (Fleet): to the outbox, in the `notifications` queue.
 * Writes no audit entry (the delivery keeps the outcome); an unexpected failure is still audited.
 */
final readonly class NotifyAgentAwaitingApproval implements SelfAuditedCommand
{
    public function __construct(public string $agentId, public string $teamId, public string $hostname)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.notify');
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
