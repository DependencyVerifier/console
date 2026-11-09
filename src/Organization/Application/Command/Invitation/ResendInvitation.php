<?php

declare(strict_types=1);

namespace Organization\Application\Command\Invitation;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Sends the invitation again with a new token and validity (3 per hour per invitation, 30 per hour per team).
 */
final readonly class ResendInvitation implements AuditableCommand
{
    public function __construct(
        public string $invitationId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.resend_invitation');
    }

    public function auditTarget(): Target
    {
        return Target::of('invitation', $this->invitationId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
