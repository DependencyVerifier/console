<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Environment\DeleteEnvironment;
use Organization\UI\Controller\RouteCollection;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/projects/{projectId}/environments/{environmentId}/delete', name: RouteCollection::ENVIRONMENT_DELETE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID, 'environmentId' => Requirement::UUID], methods: ['POST'])]
final readonly class DeleteEnvironmentController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId, string $environmentId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'environment_delete:'.$environmentId,
            static fn () => new DeleteEnvironment($environmentId, $projectId),
            'organization.environments.deleted',
            new RouteReference(RouteCollection::PROJECT_ENVIRONMENTS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]),
        );
    }
}
