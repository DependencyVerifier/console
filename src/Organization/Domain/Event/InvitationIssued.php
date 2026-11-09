<?php

declare(strict_types=1);

namespace Organization\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * Carries the plain token in memory only, to send the link after the commit. Never serialized, logged or audited.
 */
final readonly class InvitationIssued implements DomainEvent
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
