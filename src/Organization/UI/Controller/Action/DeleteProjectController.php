<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Project\DeleteProject;
use Organization\UI\Controller\RouteCollection;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/projects/{projectId}/delete', name: RouteCollection::PROJECT_DELETE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['POST'])]
final readonly class DeleteProjectController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'project_delete:'.$projectId,
            static fn () => new DeleteProject($projectId),
            'organization.projects.deleted',
            new RouteReference(RouteCollection::TEAM_PROJECTS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
