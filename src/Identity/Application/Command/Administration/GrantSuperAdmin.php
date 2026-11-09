<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Makes an account super admin.
 */
final readonly class GrantSuperAdmin implements AuditableCommand
{
    public function __construct(
        public string $userId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.grant_super_admin');
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
