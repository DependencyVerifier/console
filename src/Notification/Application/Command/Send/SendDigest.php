<?php

declare(strict_types=1);

namespace Notification\Application\Command\Send;

use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * The digest of one account, in the `notifications` queue (`make notifications-digest`).
 * Writes no audit entry (the delivery keeps the outcome); an unexpected failure is still audited.
 */
final readonly class SendDigest implements SelfAuditedCommand
{
    /**
     * @param list<string> $deliveryIds
     */
    public function __construct(public string $userId, public string $frequency, public array $deliveryIds)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('notification.send_digest');
    }

    public function auditTarget(): Target
    {
        return Target::none();
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
