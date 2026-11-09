<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

/**
 * Strict blocking at 100 %, the super admin too (decision 13), always under the team lock.
 */
final class QuotaPolicy
{
    public static function assertAllows(Team $team, QuotaName $name, int $usage): void
    {
        $quota = $team->quotas()->get($name);
        if (!$quota->allows($usage)) {
            throw new QuotaExceeded($name, $quota->limit());
        }
    }
}
