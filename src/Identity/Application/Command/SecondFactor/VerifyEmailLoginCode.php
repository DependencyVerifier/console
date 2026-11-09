<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor;

use Identity\Application\SecondFactor\CodeVerification;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * Checks an email login code. Never throws for a wrong code: the outcome is read back with GetCodeVerificationOutcome.
 */
final readonly class VerifyEmailLoginCode implements SelfAuditedCommand, CodeVerification
{
    public function __construct(
        public string $userId,
        #[\SensitiveParameter] public string $code,
        public string $attemptId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.verify_email_login_code');
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
