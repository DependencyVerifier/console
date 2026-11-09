<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Sends a new email login code (invalidates the active one), within the resend limit.
 */
final readonly class IssueEmailLoginCode implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public string $codeId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.issue_email_login_code');
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
