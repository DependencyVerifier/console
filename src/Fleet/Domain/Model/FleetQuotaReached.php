<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A team quota counted by Fleet is reached (strict blocking, the super admin too). Same rule key as Organization's
 * quotas (`rule:quota`); protocol problem `quota_exceeded`.
 */
final class FleetQuotaReached extends BusinessRuleViolated
{
    public function __construct(public readonly string $quota, public readonly int $limit)
    {
        parent::__construct('quota', \sprintf('Quota "%s" of %d reached.', $quota, $limit));
    }
}
