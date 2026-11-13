<?php

declare(strict_types=1);

namespace Notification\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * A notification recipient was attached to a project (or its link sent again): the confirmation email leaves after
 * the commit. The token travels only in this event, never stored in clear.
 */
final readonly class RecipientAttached implements DomainEvent
{
    public function __construct(public string $subscriptionId, public string $teamId, public string $userId, #[\SensitiveParameter] public string $plainToken)
    {
    }
}
