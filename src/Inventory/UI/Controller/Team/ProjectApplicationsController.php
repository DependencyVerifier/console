<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Team;

use Inventory\UI\Controller\RouteCollection;
use Inventory\UI\DataTable\ApplicationsTable;
use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\Controller\Project\ProjectPage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The Applications section of a project: the applications attached to its environments.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/applications', name: RouteCollection::PROJECT_APPLICATIONS->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class ProjectApplicationsController
{
    public function __construct(private ProjectPage $page, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        $project = $this->page->project($teamId, $projectId);

        return $this->page->render($project, ProjectPage::APPLICATIONS, [
            'applications' => $this->tables->createFromType(ApplicationsTable::class, ['teamId' => $teamId, 'projectId' => $projectId])->handleRequest($request),
        ], template: 'inventory/project/applications.html.twig');
    }
}
