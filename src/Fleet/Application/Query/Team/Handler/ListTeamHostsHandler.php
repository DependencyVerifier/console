<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team\Handler;

use Fleet\Application\Query\FleetReadModel;
use Fleet\Application\Query\Team\ListTeamHosts;
use Fleet\Application\Query\View\HostRow;
use Fleet\Application\Security\FleetAccess;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

final readonly class ListTeamHostsHandler implements QueryHandler
{
    public function __construct(private FleetAccess $access, private FleetReadModel $read)
    {
    }

    /**
     * @return Page<HostRow>
     */
    public function __invoke(ListTeamHosts $query): Page
    {
        $this->access->view($query->teamId);

        return $this->read->hosts($query->teamId, $query->page);
    }
}
