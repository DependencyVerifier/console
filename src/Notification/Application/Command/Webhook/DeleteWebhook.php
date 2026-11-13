<?php

declare(strict_types=1);

namespace Notification\Application\Command\Webhook;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A webhook deleted: the rules no longer call it, its pending deliveries are dropped when they come.
 */
final readonly class DeleteWebhook implements AuditableCommand
{
    public function __construct(public string $webhookId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.delete_webhook');
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
