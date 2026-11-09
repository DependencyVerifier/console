<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The super admin creates an account from /admin/users: no password, a setup link of 7 days is emailed. Nobody but
 * its holder ever knows its password; it belongs to no team until a team invites it.
 */
final readonly class InviteUser implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public string $requestId,
        public string $email,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.invite_user');
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
