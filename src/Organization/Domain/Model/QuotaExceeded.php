<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A team quota is reached (strict blocking, the super admin too).
 */
final class QuotaExceeded extends BusinessRuleViolated
{
    public function __construct(public readonly QuotaName $quota, public readonly int $limit)
    {
        parent::__construct('quota', \sprintf('Quota "%s" of %d reached.', $quota->value, $limit));
    }
}
