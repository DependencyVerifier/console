<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

use function Symfony\Component\String\u;

/**
 * Result of the attempt: success, failure (technical or business) or denied (authorization, rule, throttling,
 * CSRF), with a short machine reason such as `technical`, `quota`, `rule:last_team_admin_required`.
 */
final readonly class Outcome
{
    private function __construct(public OutcomeKind $kind, public string $reason)
    {
        if ('' !== $reason && [] === u($reason)->match('/^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*)?$/')) {
            throw new \InvalidArgumentException(\sprintf('Invalid outcome reason "%s".', $reason));
        }
    }

    public static function success(): self
    {
        return new self(OutcomeKind::Success, '');
    }

    public static function failure(string $reason): self
    {
        return new self(OutcomeKind::Failure, $reason);
    }

    public static function denied(string $reason): self
    {
        return new self(OutcomeKind::Denied, $reason);
    }
}
