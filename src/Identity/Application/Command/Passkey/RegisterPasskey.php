<?php

declare(strict_types=1);

namespace Identity\Application\Command\Passkey;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The account holder adds a passkey: the authenticator's answer to the creation options the server gave.
 */
final readonly class RegisterPasskey implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public string $passkeyId,
        public string $name,
        public string $response,
        public string $options,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.register_passkey');
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
