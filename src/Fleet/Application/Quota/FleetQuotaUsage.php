<?php

declare(strict_types=1);

namespace Fleet\Application\Quota;

use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\EnrollmentTokenRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Contract\Quota\QuotaUsageProvider;
use Symfony\Component\Uid\Uuid;

/**
 * The usage of Fleet's quotas, shown as enforced gauges on the team overview.
 */
final readonly class FleetQuotaUsage implements QuotaUsageProvider
{
    public function __construct(private AgentRepository $agents, private EnrollmentTokenRepository $tokens, private ClockInterface $clock)
    {
    }

    public function quotas(): array
    {
        return [FleetQuotas::MAX_AGENTS, FleetQuotas::MAX_REUSABLE_TOKENS];
    }

    public function usage(string $teamId, string $quota): int
    {
        $team = Uuid::fromString($teamId);

        return FleetQuotas::MAX_AGENTS === $quota ? $this->agents->countInQuota($team) : $this->tokens->countActiveReusable($team, $this->clock->now());
    }
}
