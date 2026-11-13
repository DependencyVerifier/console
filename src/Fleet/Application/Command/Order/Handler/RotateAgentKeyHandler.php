<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Order\Handler;

use Fleet\Application\Command\Order\RotateAgentKey;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\OrderType;
use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class RotateAgentKeyHandler implements CommandHandler
{
    public function __construct(
        private FleetAccess $access,
        private AgentRepository $agents,
        private AgentOrderRepository $orders,
        private CurrentActor $actor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RotateAgentKey $command): void
    {
        $agent = (Uuid::isValid($command->agentId) ? $this->agents->find(AgentId::fromString($command->agentId)) : null) ?? throw ResourceNotFound::of('agent');
        $this->access->assert(Permission::HostOrder, $agent->teamId()->toRfc4122());
        if (true === $this->orders->lastOf($agent->id(), OrderType::RotateKey)?->status()->isOpen()) {
            return; // one rotation on its way at a time
        }

        $this->orders->add(AgentOrder::rotateKey(AgentOrderId::of(Uuid::v7()), $agent, $this->actor->actor()->userId(), $this->clock->now()));
    }
}
