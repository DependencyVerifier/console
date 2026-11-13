<?php

declare(strict_types=1);

namespace Identity\Application\Command\Passkey;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Removes a passkey, by its holder or by a super admin (`instance.users.manage`). Every session is signed out.
 */
final readonly class RevokePasskey implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public string $passkeyId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.revoke_passkey');
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
