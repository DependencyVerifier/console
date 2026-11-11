<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Quota;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The usage of team quotas counted by another context (Fleet: agents, reusable enrollment tokens). Organization
 * collects the providers to show the gauges as enforced; each context enforces its own quotas under its lock.
 */
#[AutoconfigureTag]
interface QuotaUsageProvider
{
    /**
     * @return list<string> the quota names (QuotaName values) this provider counts
     */
    public function quotas(): array;

    public function usage(string $teamId, string $quota): int;
}
