<?php

declare(strict_types=1);

namespace Fleet\Application\Quota;

use Fleet\Domain\Model\FleetQuotaReached;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\EnrollmentTokenRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Shared\Application\Locking\TransactionLock;
use Symfony\Component\Uid\Uuid;

/**
 * The team quotas Fleet enforces (docs/cadrage.md §5ter): maxAgents (pending and approved agents; an unused token
 * reserves no seat) and maxReusableEnrollmentTokens (active reusable tokens). Every command creating or bringing back
 * one of them locks the team's fleet first, then reads again what it changes and counts: two enrollments never both
 * take the last seat. Always this order (team lock, then rows), so two commands never wait on each other in a cycle.
 */
final readonly class FleetQuotas
{
    public const string MAX_AGENTS = 'max_agents';
    public const string MAX_REUSABLE_TOKENS = 'max_reusable_enrollment_tokens';
    public const string MIN_SCAN_INTERVAL = 'min_scan_interval_minutes';

    public function __construct(
        private TransactionLock $lock,
        private QueryBus $queries,
        private AgentRepository $agents,
        private EnrollmentTokenRepository $tokens,
        private ClockInterface $clock,
    ) {
    }

    public function lock(Uuid $teamId): void
    {
        $this->lock->acquire('fleet.team:'.$teamId->toRfc4122());
    }

    /**
     * One more pending or approved agent (enrollment, approval, reactivation). Call after lock().
     */
    public function assertAgentSeat(Uuid $teamId): void
    {
        $this->assertBelow(self::MAX_AGENTS, $teamId, $this->agents->countInQuota($teamId));
    }

    /**
     * Approving a pending agent adds no seat (it already counts) but is refused while the team is over its limit
     * (the quota was lowered under the usage). Call after lock().
     */
    public function assertAgentsWithinLimit(Uuid $teamId): void
    {
        $limit = $this->limit($teamId, self::MAX_AGENTS);
        if (null !== $limit && $this->agents->countInQuota($teamId) > $limit) {
            throw new FleetQuotaReached(self::MAX_AGENTS, $limit);
        }
    }

    /**
     * One more active reusable token. Call after lock().
     */
    public function assertReusableTokenSeat(Uuid $teamId): void
    {
        $this->assertBelow(self::MAX_REUSABLE_TOKENS, $teamId, $this->tokens->countActiveReusable($teamId, $this->clock->now()));
    }

    public function limit(Uuid $teamId, string $quota): ?int
    {
        return $this->queries->ask(new GetTeamQuota($teamId->toRfc4122(), $quota));
    }

    private function assertBelow(string $quota, Uuid $teamId, int $usage): void
    {
        $limit = $this->limit($teamId, $quota);
        if (null !== $limit && $usage >= $limit) {
            throw new FleetQuotaReached($quota, $limit);
        }
    }
}
