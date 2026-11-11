<?php

declare(strict_types=1);

namespace Inventory\Application\Agent;

use Inventory\Domain\Model\AgentPending;
use Inventory\Domain\Model\InventoryRun;
use Inventory\Domain\Repository\InventoryRunRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Fleet\AgentContext;
use Shared\Application\Contract\Fleet\GetAgentContext;
use Symfony\Component\Uid\Uuid;

/**
 * The agent behind an inventory request (authenticated by Fleet's firewall): only an approved agent sends one
 * (protocol §6.3), and only its own inventories exist for it (404 otherwise).
 */
final readonly class InventoryAgents
{
    public function __construct(private QueryBus $queries, private InventoryRunRepository $runs)
    {
    }

    public function approved(string $agentId): AgentContext
    {
        $agent = $this->queries->ask(new GetAgentContext($agentId));
        if (!$agent->isApproved()) {
            throw new AgentPending();
        }

        return $agent;
    }

    public function runOf(AgentContext $agent, string $inventoryId): InventoryRun
    {
        $run = Uuid::isValid($inventoryId) ? $this->runs->find(Uuid::fromString($inventoryId)) : null;
        if (null === $run || !$run->isFrom(Uuid::fromString($agent->agentId))) {
            throw ResourceNotFound::of('inventory');
        }

        return $run;
    }
}
