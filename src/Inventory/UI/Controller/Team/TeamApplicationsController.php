<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Team;

use Inventory\UI\Controller\RouteCollection;
use Inventory\UI\DataTable\ApplicationsTable;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The applications of the team: those to attach first (members only), then every application the actor sees.
 */
#[Route(path: '/teams/{teamId}/applications', name: RouteCollection::TEAM_APPLICATIONS->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class TeamApplicationsController
{
    public function __construct(private InventoryPage $page, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        return $this->page->render($teamId, 'applications', 'team_applications', [
            'unattached' => $this->tables->createFromType(ApplicationsTable::class, ['teamId' => $teamId, 'unattached' => true, 'name' => 'unattached'])->handleRequest($request),
            'applications' => $this->tables->createFromType(ApplicationsTable::class, ['teamId' => $teamId, 'attached' => true])->handleRequest($request),
        ]);
    }
}
