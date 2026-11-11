<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Project;

use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\DataTable\EnvironmentsTable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Environments section of a project, as a DataTable drawn from this URL.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/environments', name: RouteCollection::PROJECT_ENVIRONMENTS->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class ProjectEnvironmentsController
{
    public function __construct(private ProjectPage $page, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        $project = $this->page->project($teamId, $projectId);

        return $this->page->render($project, ProjectPage::ENVIRONMENTS, [
            'environments' => $this->tables->createFromType(EnvironmentsTable::class, ['projectId' => $projectId])->handleRequest($request),
        ]);
    }
}
