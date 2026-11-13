<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A manager attaches an email to the notifications of a project (`project.notifications.manage`): a
 * notification-only account, admitted as viewer of the project, silent until it confirms its address. The email is
 * not audited (the account id is, through the grants of Organization).
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
