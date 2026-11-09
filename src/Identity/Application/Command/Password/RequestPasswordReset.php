<?php

declare(strict_types=1);

namespace Identity\Application\Command\Password;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Same answer whether the account exists or not; the email leaves after the commit, once the response is sent.
 */
final readonly class RequestPasswordReset implements AuditableCommand
{
    public function __construct(
        public string $requestId,
        public string $email,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.request_password_reset');
    }

    public function auditTarget(): Target
    {
        return Target::of('password_reset_request', $this->requestId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
