<?php

declare(strict_types=1);

namespace Identity\Application\Command\Password;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The authenticated user changes their own password (requires a recent authentication). Revokes every session.
 */
final readonly class ChangePassword implements AuditableCommand
{
    public function __construct(
        public string $userId,
        #[\SensitiveParameter] public string $newPassword,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.change_password');
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
