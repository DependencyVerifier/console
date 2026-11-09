<?php

declare(strict_types=1);

namespace Identity\Application\Command\Password;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Consumes a reset link and sets a new password. The second factor is still required afterwards.
 */
final readonly class ResetPassword implements AuditableCommand
{
    public function __construct(
        public string $requestId,
        #[\SensitiveParameter] public string $newPassword,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.reset_password');
    }

    public function auditTarget(): Target
    {
        return Target::of('password_reset_request', $this->requestId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
