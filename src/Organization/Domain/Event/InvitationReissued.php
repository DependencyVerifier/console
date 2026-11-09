<?php

declare(strict_types=1);

namespace Organization\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * Same as InvitationIssued, for a resent invitation (new token, new validity).
 */
final readonly class InvitationReissued implements DomainEvent
{
    public function __construct(public string $invitationId, public string $email, #[\SensitiveParameter] public string $plainToken)
    {
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('An event carrying a secret is never serialized.');
    }
}
