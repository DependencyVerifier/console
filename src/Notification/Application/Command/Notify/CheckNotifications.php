<?php

declare(strict_types=1);

namespace Notification\Application\Command\Notify;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * What no event tells (`make notifications-check`, every hour): the agents gone silent, the risk acceptances
 * expiring within a week. Each one is told once.
 */
final readonly class CheckNotifications implements AuditableCommand
{
    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.check');
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
