<?php

declare(strict_types=1);

namespace Identity\Application\Command\Account;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Creates the account of an invitee with the password they chose. The email comes from the pending invitation, never
 * from the form.
 */
final readonly class ActivateInvitedAccount implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public string $invitationId,
        #[\SensitiveParameter] public string $password,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.activate_invited_account');
    }

    public function auditTarget(): Target
    {
        return Target::of('user', $this->userId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
