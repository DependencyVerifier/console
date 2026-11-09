<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * Carries the plain link token in memory only (same rule as EmailLoginCodeIssued).
 */
final readonly class PasswordResetRequested implements DomainEvent
{
    public function __construct(public string $requestId, public string $userId, #[\SensitiveParameter] public string $plainToken)
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
