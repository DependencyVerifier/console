<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A manager invites an email to the notifications of a project (`project.notifications.manage`): nothing but an
 * invitation exists until the holder of the address confirms it. The same answer whatever the address. The email is
 * not audited.
 */
final readonly class AttachRecipient implements AuditableCommand
{
    public function __construct(public string $teamId, public string $projectId, public string $email, public string $frequency)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.attach_recipient', ['frequency']);
    }

    public function auditTarget(): Target
    {
        return Target::of('project', $this->projectId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['frequency' => $this->frequency]);
    }
}
