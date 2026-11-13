<?php

declare(strict_types=1);

namespace Notification\Application\Command\Rule;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A rule of a project (`project.notifications.manage`) or of the fleet of the team (no project, `team.channels.manage`). Audited on its parent, like every creation.
 */
final readonly class DefineRule implements AuditableCommand
{
    public function __construct(public string $ruleId, public string $teamId, public ?string $projectId, public RuleSettings $settings)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.define_rule', ['rule_id']);
    }

    public function auditTarget(): Target
    {
        return null === $this->projectId ? Target::of('team', $this->teamId) : Target::of('project', $this->projectId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['rule_id' => $this->ruleId]);
    }
}
