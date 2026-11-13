<?php

declare(strict_types=1);

namespace Notification\Application\Command\Webhook;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Posts a signed `test` request at once; the outcome is kept on the webhook (its last call).
 */
final readonly class TestWebhook implements AuditableCommand
{
    public function __construct(public string $webhookId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.test_webhook');
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
