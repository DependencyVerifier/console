<?php

declare(strict_types=1);

namespace Notification\Application\Command\Webhook;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Renames a webhook or changes its URL (its secret stays).
 */
final readonly class EditWebhook implements AuditableCommand
{
    public function __construct(public string $webhookId, public string $name, public string $url)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.edit_webhook');
    }

    public function auditTarget(): Target
    {
        return Target::of('notification_webhook', $this->webhookId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
