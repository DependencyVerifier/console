<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The edit dialog of /admin/users: the display name (empty clears it). Super admin and email have their own
 * gestures (re-authentication, confirmation by link).
 */
final readonly class UpdateUser implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public string $displayName,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.update_user');
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
