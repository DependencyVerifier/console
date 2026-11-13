<?php

declare(strict_types=1);

namespace Notification\Application\Command\Subscription;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The actor's own subscription to the notifications of a project (`project.notifications.subscribe`) or of the fleet
 * (a member who sees it): at once, daily, weekly, or off.
 */
final readonly class Subscribe implements AuditableCommand
{
    public function __construct(public string $teamId, public ?string $projectId, public string $frequency)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.subscribe', ['frequency']);
    }

    public function auditTarget(): Target
    {
        return null === $this->projectId ? Target::of('team', $this->teamId) : Target::of('project', $this->projectId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['frequency' => $this->frequency]);
    }
}
