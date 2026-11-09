<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

use function Symfony\Component\String\u;

/**
 * What was attempted, e.g. `identity.login` or `organization.invite_member`. Carries the whitelist of context keys
 * an entry of this action may hold, so the audit context is safe by construction (no free-form payload).
 */
final readonly class AuditAction
{
    /**
     * @param list<string> $allowedContextKeys
     */
    private function __construct(public string $name, public array $allowedContextKeys)
    {
        if ([] === u($name)->match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/')) {
            throw new \InvalidArgumentException(\sprintf('Invalid audit action "%s".', $name));
        }

        foreach ($allowedContextKeys as $key) {
            if ([] === u($key)->match('/^[a-z][a-z0-9_]{0,63}$/')) {
                throw new \InvalidArgumentException(\sprintf('Invalid audit context key "%s".', $key));
            }
        }
    }

    /**
     * @param list<string> $allowedContextKeys
     */
    public static function named(string $name, array $allowedContextKeys = []): self
    {
        return new self($name, $allowedContextKeys);
    }

    public function allows(string $contextKey): bool
    {
        return \in_array($contextKey, $this->allowedContextKeys, true);
    }
}
