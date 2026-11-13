<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A manager withdraws an invitation still pending: its link no longer works.
 */
final readonly class CancelRecipientInvitation implements AuditableCommand
{
    public function __construct(public string $invitationId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.cancel_recipient_invitation');
    }

    public function auditTarget(): Target
    {
        return Target::of('notification_recipient_invitation', $this->invitationId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
