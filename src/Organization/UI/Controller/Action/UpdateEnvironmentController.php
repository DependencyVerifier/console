<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Environment\UpdateEnvironment;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\EnvironmentType;
use Shared\UI\Action\FormData;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/projects/{projectId}/environments/{environmentId}/update', name: RouteCollection::ENVIRONMENT_UPDATE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID, 'environmentId' => Requirement::UUID], methods: ['POST'])]
final readonly class UpdateEnvironmentController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId, string $environmentId): Response
    {
        return $this->action->handle(
            $request,
            'environment',
            'environment_update:'.$environmentId,
            static fn ($form) => new UpdateEnvironment($environmentId, $projectId, FormData::string($form, 'name'), true === $form->get('production')->getData()),
            'organization.environments.updated',
            new RouteReference(RouteCollection::PROJECT_ENVIRONMENTS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]), EnvironmentType::class, [],
        );
    }
}
