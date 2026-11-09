<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * An account was invited from /admin/users: its setup link leaves after the commit. Carries the plain token in
 * memory only (same rule as PasswordResetRequested).
 */
final readonly class AccountSetupRequested implements DomainEvent
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
