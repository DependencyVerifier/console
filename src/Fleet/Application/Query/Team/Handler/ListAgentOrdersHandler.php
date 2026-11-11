<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team\Handler;

use Fleet\Application\Query\FleetReadModel;
use Fleet\Application\Query\Team\ListAgentOrders;
use Fleet\Application\Query\View\OrderRow;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Repository\AgentRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;
use Symfony\Component\Uid\Uuid;

/**
 * The orders of an agent, to whom sees the fleet of its real team.
 */
final readonly class ListAgentOrdersHandler implements QueryHandler
{
    public function __construct(private FleetAccess $access, private AgentRepository $agents, private FleetReadModel $read)
    {
    }

    /**
     * @return Page<OrderRow>
     */
    public function __invoke(ListAgentOrders $query): Page
    {
        $agent = (Uuid::isValid($query->agentId) ? $this->agents->find(AgentId::fromString($query->agentId)) : null) ?? throw ResourceNotFound::of('agent');
        $this->access->view($agent->teamId()->toRfc4122());

        return $this->read->orders($query->agentId, $query->page);
    }
}
