<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Order\Handler;

use Fleet\Application\Command\Order\RequestScan;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\OrderType;
use Fleet\Domain\Model\ScanAlreadyRequested;
use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;

final readonly class RequestScanHandler implements CommandHandler
{
    public const string RATE = '-5 minutes';

    public function __construct(
        private FleetAccess $access,
        private AgentRepository $agents,
        private AgentOrderRepository $orders,
        private CurrentActor $actor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestScan $command): void
    {
        $agent = $this->agents->find(AgentId::fromString($command->agentId)) ?? throw ResourceNotFound::of('agent');
        $this->access->assert(Permission::HostOrder, $agent->teamId()->toRfc4122());
        $now = $this->clock->now();
        $last = $this->orders->lastOf($agent->id(), OrderType::ScanNow);
        if (null !== $last && $last->createdAt() > $now->modify(self::RATE)) {
            throw new ScanAlreadyRequested();
        }

        $this->orders->add(AgentOrder::scanNow(AgentOrderId::fromString($command->orderId), $agent, $this->actor->actor()->userId(), $now));
    }
}
