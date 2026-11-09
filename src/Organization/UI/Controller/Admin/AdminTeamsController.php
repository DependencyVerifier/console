<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Admin;

use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\DataTable\TeamsTable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Teams of the instance (DataTable). Creating one opens the "Create a team" dialog (AdminTeamCreateController).
 */
#[Route(path: '/admin/teams', name: RouteCollection::ADMIN_TEAMS->value, methods: ['GET', 'POST'])]
final class AdminTeamsController extends AbstractController
{
    public function __construct(private readonly DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request): Response
    {
        $table = $this->tables->createFromType(TeamsTable::class)->handleRequest($request);
        if ($table->isCallback()) {
            return $table->getResponse();
        }

        return $this->render('organization/admin/teams.html.twig', ['table' => $table, 'nav_active' => 'admin']);
    }
}
