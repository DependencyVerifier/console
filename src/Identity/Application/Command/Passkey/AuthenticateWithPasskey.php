<?php

declare(strict_types=1);

namespace Identity\Application\Command\Passkey;

use Identity\Application\SecondFactor\PasskeyVerification;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Signing in with a passkey, without password nor second factor (user verification makes it multi-factor): the account is the one its user handle claims, checked by the signature.
 */
final readonly class AuthenticateWithPasskey implements PasskeyVerification
{
    public function __construct(
        public string $userId,
        #[\SensitiveParameter] public string $code,
        public string $attemptId,
        public string $options,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.login_passkey');
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
