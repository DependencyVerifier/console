<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Team;

use Exposure\UI\DataTable\FindingsTable;
use Inventory\Application\Query\Team\GetHostInventory;
use Inventory\Application\Query\Team\ListInventoryHistory;
use Inventory\Application\Query\Team\ListPackages;
use Inventory\UI\Controller\RouteCollection;
use Inventory\UI\DataTable\ApplicationsTable;
use Inventory\UI\DataTable\HistoryTable;
use Inventory\UI\DataTable\PackagesTable;
use Omines\DataTablesBundle\DataTableFactory;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A host as its last inventory saw it: facts, runtimes, OS packages, applications (a TEAM_GUEST sees the hosts of its
 * applications, and those applications only).
 */
#[Route(path: '/teams/{teamId}/hosts/{hostId}', name: RouteCollection::HOST->value, requirements: ['teamId' => Requirement::UUID, 'hostId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class HostController
{
    public function __construct(private InventoryPage $page, private QueryBus $queries, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId, string $hostId): Response
    {
        $host = $this->queries->ask(new GetHostInventory($teamId, $hostId));

        return $this->page->render($teamId, 'host', 'team_hosts', [
            'applications' => $this->tables->createFromType(ApplicationsTable::class, ['teamId' => $teamId, 'hostId' => $hostId])->handleRequest($request),
            'packages' => $this->tables->createFromType(PackagesTable::class, ['teamId' => $teamId, 'of' => ListPackages::HOST, 'id' => $hostId])->handleRequest($request),
            'findings' => $this->tables->createFromType(FindingsTable::class, ['teamId' => $teamId, 'subjectType' => 'host', 'subjectId' => $hostId])->handleRequest($request),
            'history' => $this->tables->createFromType(HistoryTable::class, ['teamId' => $teamId, 'of' => ListInventoryHistory::HOST, 'id' => $hostId])->handleRequest($request),
        ], ['host' => $host]);
    }
}
