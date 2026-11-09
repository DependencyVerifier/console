<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Published command of Organization, dispatched by Identity's invitation pages once the account exists and is signed
 * in. Idempotent: an existing membership just marks the invitation accepted.
 */
final readonly class AcceptInvitation implements AuditableCommand
{
    public function __construct(public string $invitationId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.accept_invitation');
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
