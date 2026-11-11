<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Maintenance\Handler;

use Fleet\Application\Command\Agent\Handler\AgentRevocation;
use Fleet\Application\Command\Maintenance\MaintainFleet;
use Fleet\Application\Port\NonceStore;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;

/**
 * Runs by batches (the next run takes the rest). A revoked inactive agent keeps its history; it would need a new
 * installation to come back, like any revoked agent.
 */
final readonly class MaintainFleetHandler implements CommandHandler
{
    public const string INACTIVE_REVOKED_AFTER = '-37 days';
    public const string NONCE_MEMORY = '-10 minutes';

    public function __construct(
        private AgentRepository $agents,
        private AgentOrderRepository $orders,
        private AgentRevocation $revocation,
        private NonceStore $nonces,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(MaintainFleet $command): void
    {
        $now = $this->clock->now();
        $report = $command->report;
        foreach ($this->agents->approvedSilentSince($now->modify(Agent::INACTIVE_AFTER), $command->batch) as $agent) {
            $report->inactive += (int) $agent->markInactiveIfSilent($now);
        }

        foreach ($this->agents->inactiveSilentSince($now->modify(self::INACTIVE_REVOKED_AFTER), $command->batch) as $agent) {
            $this->revocation->revoke($agent);
            ++$report->revoked;
        }

        // Past their validity; expireIfLate() keeps the 60 s of grace for a late result.
        foreach ($this->orders->deliveredExpiredBefore($now, $command->batch) as $order) {
            $report->expiredOrders += (int) $order->expireIfLate($now);
        }

        $report->purgedNonces = $this->nonces->purgeBefore($now->modify(self::NONCE_MEMORY));
    }
}
