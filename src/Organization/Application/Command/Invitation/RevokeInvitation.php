<?php

declare(strict_types=1);

namespace Organization\Application\Command\Invitation;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Revokes a pending invitation: its link stops working.
 */
final readonly class RevokeInvitation implements AuditableCommand
{
    public function __construct(
        public string $invitationId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.revoke_invitation');
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
