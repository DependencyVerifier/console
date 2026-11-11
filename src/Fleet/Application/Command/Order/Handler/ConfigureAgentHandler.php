<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Order\Handler;

use Fleet\Application\Command\Order\ConfigureAgent;
use Fleet\Application\Quota\FleetQuotas;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\AgentConfig;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentNotApproved;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\OrderStatus;
use Fleet\Domain\Model\OrderType;
use Fleet\Domain\Model\ScanIntervalBelowMinimum;
use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;

/**
 * host.order on the agent's team; the interval never below the team's minimum. A SET_CONFIG not yet signed is
 * cancelled: only the newest configuration matters.
 */
final readonly class ConfigureAgentHandler implements CommandHandler
{
    public function __construct(
        private FleetAccess $access,
        private AgentRepository $agents,
        private AgentOrderRepository $orders,
        private FleetQuotas $quotas,
        private CurrentActor $actor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ConfigureAgent $command): void
    {
        $agent = $this->agents->find(AgentId::fromString($command->agentId)) ?? throw ResourceNotFound::of('agent');
        $this->access->assert(Permission::HostOrder, $agent->teamId()->toRfc4122());
        $minimum = $this->quotas->limit($agent->teamId(), FleetQuotas::MIN_SCAN_INTERVAL);
        if (null !== $minimum && $command->scanIntervalMinutes < $minimum) {
            throw new ScanIntervalBelowMinimum($minimum);
        }

        if (!$agent->isApproved()) {
            throw new AgentNotApproved(); // before its desired configuration changes
        }

        $config = new AgentConfig($command->scanIntervalMinutes, $command->discoveryRoots, $command->declaredPaths, $command->exclusions);
        $order = AgentOrder::setConfig(AgentOrderId::fromString($command->orderId), $agent, $agent->configure($config), $this->actor->actor()->userId(), $this->clock->now());
        foreach ($this->orders->openOf($agent->id(), 1000) as $open) {
            if (OrderType::SetConfig === $open->type() && OrderStatus::Queued === $open->status()) {
                $open->cancel();
            }
        }

        $this->orders->add($order);
    }
}
