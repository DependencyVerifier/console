<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Team;

use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\DataTable\ProjectsTable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Projects tab: the projects the actor may see (the query handler filters), as a DataTable drawn from this URL.
 */
#[Route(path: '/teams/{teamId}/projects', name: RouteCollection::TEAM_PROJECTS->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class TeamProjectsController
{
    public function __construct(private TeamPage $page, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        $team = $this->page->team($teamId);

        return $this->page->render($team, TeamPage::PROJECTS, [
            'projects' => $this->tables->createFromType(ProjectsTable::class, ['teamId' => $teamId])->handleRequest($request),
        ]);
    }
}
