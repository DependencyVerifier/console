<?php

declare(strict_types=1);

namespace Notification\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * A manager invited an email to the notifications of a project (or sent its link again): the confirmation email
 * leaves after the commit. The token travels only in this event, never stored in clear.
 */
final readonly class RecipientInvited implements DomainEvent
{
    public function __construct(public string $invitationId, public string $teamId, public string $projectId, public string $email, #[\SensitiveParameter] public string $plainToken)
    {
    }
}
