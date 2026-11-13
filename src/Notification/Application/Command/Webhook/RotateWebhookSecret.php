<?php

declare(strict_types=1);

namespace Notification\Application\Command\Webhook;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;
use Shared\Application\Security\RevealsSecret;

/**
 * A new secret, shown once: the former one no longer signs anything.
 */
final readonly class RotateWebhookSecret implements AuditableCommand, RevealsSecret
{
    public function __construct(public string $webhookId, #[\SensitiveParameter] public string $plainSecret)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.rotate_webhook_secret');
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
