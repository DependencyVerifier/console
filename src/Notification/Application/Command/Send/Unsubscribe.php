<?php

declare(strict_types=1);

namespace Notification\Application\Command\Send;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The unsubscribe link of an email (signed by the UI, no login): the subscription is turned off.
 */
final readonly class Unsubscribe implements AuditableCommand
{
    public function __construct(public string $subscriptionId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.unsubscribe');
    }

    public function auditTarget(): Target
    {
        return Target::of('notification_subscription', $this->subscriptionId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
