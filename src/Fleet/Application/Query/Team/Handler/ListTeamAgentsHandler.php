<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team\Handler;

use Fleet\Application\Query\FleetReadModel;
use Fleet\Application\Query\Team\ListTeamAgents;
use Fleet\Application\Query\View\AgentRow;
use Fleet\Application\Security\FleetAccess;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

final readonly class ListTeamAgentsHandler implements QueryHandler
{
    public function __construct(private FleetAccess $access, private FleetReadModel $read)
    {
    }

    /**
     * @return Page<AgentRow>
     */
    public function __invoke(ListTeamAgents $query): Page
    {
        $this->access->view($query->teamId);

        return $this->read->agents($query->teamId, $query->page);
    }
}
