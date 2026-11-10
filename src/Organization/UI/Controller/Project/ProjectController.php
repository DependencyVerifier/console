<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Project;

use Omines\DataTablesBundle\DataTableFactory;
use Organization\Application\Query\Project\GetProject;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\DataTable\EnvironmentsTable;
use Organization\UI\DataTable\ProjectMembersTable;
use Organization\UI\Form\EnvironmentType;
use Organization\UI\Form\InviteType;
use Organization\UI\Form\NameType;
use Shared\Application\Bus\QueryBus;
use Shared\UI\Action\ActionButtonType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Project page: environments and members as DataTables. The project must belong to the team of the URL (404).
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}', name: RouteCollection::PROJECT->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET', 'POST'])]
final class ProjectController extends AbstractController
{
    public function __construct(private readonly QueryBus $queries, private readonly DataTableFactory $tables, private readonly FormFactoryInterface $forms)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        $project = $this->queries->ask(new GetProject($projectId));
        if ($project->teamId !== $teamId) {
            throw $this->createNotFoundException();
        }

        $tables = [
            'environments' => $this->tables->createFromType(EnvironmentsTable::class, ['projectId' => $projectId])->handleRequest($request),
            'members' => $this->tables->createFromType(ProjectMembersTable::class, ['projectId' => $projectId])->handleRequest($request),
        ];
        foreach ($tables as $table) {
            if ($table->isCallback()) {
                return $table->getResponse();
            }
        }

        return $this->render('organization/project/show.html.twig', [
            'project' => $project,
            'tables' => $tables,
            'nav_active' => 'team_projects',
            'current_team' => $teamId,
            'environment_form' => $this->forms->createNamed('environment', EnvironmentType::class, null, ['csrf_token_id' => 'environment_create:'.$projectId])->createView(),
            'invite_form' => $this->forms->createNamed('invite', InviteType::class, null, ['csrf_token_id' => 'project_invite:'.$projectId, 'project' => true])->createView(),
            'rename_form' => $this->forms->createNamed('project_name', NameType::class, ['name' => $project->name], ['csrf_token_id' => 'project_rename:'.$projectId])->createView(),
            'delete_form' => $this->forms->createNamed('action', ActionButtonType::class, null, ['csrf_token_id' => 'project_delete:'.$projectId])->createView(),
        ]);
    }
}
