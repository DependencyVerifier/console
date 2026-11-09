<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Turns TOTP off (or abandons a pending enrolment); the email code becomes the second factor again.
 */
final readonly class DisableTotp implements AuditableCommand
{
    public function __construct(
        public string $userId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.disable_totp');
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
