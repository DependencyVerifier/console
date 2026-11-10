<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The "Change email" dialog of /admin/users (re-authenticated): the new email is not applied, a confirmation link
 * leaves for it (EmailChangeRequest), the account keeps its email until it is clicked. Never on a super admin.
 */
final readonly class ChangeUserEmail implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $emailChangeId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.change_user_email');
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
