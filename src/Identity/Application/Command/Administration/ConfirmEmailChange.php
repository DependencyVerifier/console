<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The holder of the new address clicked its link: the email changes (knowing the token is the authorization).
 */
final readonly class ConfirmEmailChange implements AuditableCommand
{
    public function __construct(
        public string $requestId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.confirm_email_change');
    }

    public function auditTarget(): Target
    {
        return Target::of('email_change_request', $this->requestId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
