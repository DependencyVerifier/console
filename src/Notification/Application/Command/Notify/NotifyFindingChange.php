<?php

declare(strict_types=1);

namespace Notification\Application\Command\Notify;

use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * A finding changed (Exposure): to the outbox, in the `notifications` queue.
 * Writes no audit entry (the delivery keeps the outcome); an unexpected failure is still audited.
 */
final readonly class NotifyFindingChange implements SelfAuditedCommand
{
    public function __construct(public string $findingId, public string $teamId, public string $change, public string $at)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.notify');
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
