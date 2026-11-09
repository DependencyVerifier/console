<?php

declare(strict_types=1);

namespace Identity\Application\Command\Account;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Creates the first (or another) super admin from the CLI. The password is never displayed.
 */
final readonly class CreateSuperAdmin implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public string $email,
        #[\SensitiveParameter] public string $password,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.create_super_admin');
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
