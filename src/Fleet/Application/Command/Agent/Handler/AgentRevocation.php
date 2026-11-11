<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Agent\Handler;

use Fleet\Domain\Model\Agent;
use Fleet\Domain\Repository\AgentOrderRepository;
use Psr\Clock\ClockInterface;

/**
 * Revoking an agent cancels what it was still to receive.
 */
final readonly class AgentRevocation
{
    private const int ALL_OPEN = 1000;

    public function __construct(private AgentOrderRepository $orders, private ClockInterface $clock)
    {
    }

    public function revoke(Agent $agent): void
    {
        $agent->revoke($this->clock->now());
        foreach ($this->orders->openOf($agent->id(), self::ALL_OPEN) as $order) {
            $order->cancel();
        }
    }
}
