<?php

declare(strict_types=1);

namespace Identity\Application\Command\Passkey;

use Identity\Application\SecondFactor\PasskeyVerification;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A passkey as the second factor (or to authenticate again): the assertion is checked like a code (SecondFactorAttempt).
 */
final readonly class VerifyPasskey implements PasskeyVerification
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
        return AuditAction::named('identity.verify_passkey');
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
