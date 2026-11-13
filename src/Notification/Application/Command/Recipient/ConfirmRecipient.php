<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The confirmation link of a recipient (no login: the token is the authorization), followed with a POST.
 */
final readonly class ConfirmRecipient implements AuditableCommand
{
    public function __construct(#[\SensitiveParameter] public string $token)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.confirm_recipient');
    }

    public function auditTarget(): Target
    {
        return Target::none();
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
