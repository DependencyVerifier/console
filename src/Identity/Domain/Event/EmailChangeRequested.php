<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * The confirmation link leaves for the new address after the commit; the plain token stays in memory only.
 */
final readonly class EmailChangeRequested implements DomainEvent
{
    public function __construct(public string $requestId, public string $userId, public string $newEmail, #[\SensitiveParameter] public string $plainToken)
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
