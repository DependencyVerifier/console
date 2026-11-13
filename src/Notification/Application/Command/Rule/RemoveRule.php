<?php

declare(strict_types=1);

namespace Notification\Application\Command\Rule;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A rule removed: its notices already written still leave.
 */
final readonly class RemoveRule implements AuditableCommand
{
    public function __construct(public string $ruleId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.remove_rule');
    }

    public function auditTarget(): Target
    {
        return Target::of('notification_rule', $this->ruleId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
