<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Super admin rescue of an account that lost its TOTP: back to the email code. Revokes every session.
 */
final readonly class ResetSecondFactor implements AuditableCommand
{
    public function __construct(
        public string $userId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.reset_second_factor');
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
