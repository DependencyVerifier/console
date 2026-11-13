<?php

declare(strict_types=1);

namespace Notification\Application\Command\Rule;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Everything of a rule but its scope.
 */
final readonly class RedefineRule implements AuditableCommand
{
    public function __construct(public string $ruleId, public RuleSettings $settings)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.redefine_rule');
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
