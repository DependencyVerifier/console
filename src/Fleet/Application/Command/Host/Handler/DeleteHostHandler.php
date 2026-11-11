<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Host\Handler;

use Fleet\Application\Command\Host\DeleteHost;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\AgentStatus;
use Fleet\Domain\Model\HostHasAgents;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\HostRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class DeleteHostHandler implements CommandHandler
{
    public function __construct(private FleetAccess $access, private HostRepository $hosts, private AgentRepository $agents, private AgentOrderRepository $orders)
    {
    }

    public function __invoke(DeleteHost $command): void
    {
        $host = $this->hosts->find(HostId::fromString($command->hostId)) ?? throw ResourceNotFound::of('host');
        $this->access->assert(Permission::HostDelete, $host->teamId()->toRfc4122());
        $agents = $this->agents->ofHost($host->id());
        foreach ($agents as $agent) {
            if (AgentStatus::Revoked !== $agent->status()) {
                throw new HostHasAgents();
            }
        }

        foreach ($agents as $agent) {
            $this->orders->deleteOfAgent($agent->id());
            $this->agents->remove($agent);
        }

        $this->hosts->remove($host);
    }
}
