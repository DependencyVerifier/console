<?php

declare(strict_types=1);

namespace Notification\Application\Command\Send;

use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * One attempt of an immediate delivery, in the `notifications` queue (at once, then from `make notifications-check`).
 * Writes no audit entry (the delivery keeps the outcome); an unexpected failure is still audited.
 */
final readonly class SendDelivery implements SelfAuditedCommand
{
    public function __construct(public string $deliveryId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.send');
    }

    public function auditTarget(): Target
    {
        return Target::of('notification_delivery', $this->deliveryId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
