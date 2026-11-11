<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Project;

use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\DataTable\InvitationsTable;
use Organization\UI\DataTable\ProjectMembersTable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Members section of a project: its members, then its pending invitations for whoever manages them, as DataTables
 * drawn from this URL.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/members', name: RouteCollection::PROJECT_MEMBERS->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class ProjectMembersController
{
    public function __construct(private ProjectPage $page, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        $project = $this->page->project($teamId, $projectId);

        $tables = ['members' => $this->tables->createFromType(ProjectMembersTable::class, ['projectId' => $projectId])->handleRequest($request)];
        if ($project->can('project.members.manage')) {
            $tables['invitations'] = $this->tables->createFromType(InvitationsTable::class, ['teamId' => $teamId, 'projectId' => $projectId])->handleRequest($request);
        }

        return $this->page->render($project, ProjectPage::MEMBERS, $tables);
    }
}
