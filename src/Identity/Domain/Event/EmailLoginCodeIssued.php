<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * Carries the plain code in memory only, to send it after the commit. Never serialized, logged or audited: the
 * event bus stays synchronous (an asynchronous bus would need a single-use id instead).
 */
final readonly class EmailLoginCodeIssued implements DomainEvent
{
    public function __construct(public string $userId, #[\SensitiveParameter] public string $plainCode)
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
