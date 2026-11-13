<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A manager detaches a recipient from the notifications of a project: its subscription is removed (its membership,
 * which grants nothing to an account that cannot sign in, is removed in Organization's members tab).
 */
final readonly class DetachRecipient implements AuditableCommand
{
    public function __construct(public string $subscriptionId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.detach_recipient');
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
