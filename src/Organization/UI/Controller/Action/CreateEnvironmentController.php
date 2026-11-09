<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Environment\CreateEnvironment;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\EnvironmentType;
use Shared\UI\Action\FormData;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route(path: '/teams/{teamId}/projects/{projectId}/environments', name: RouteCollection::ENVIRONMENT_CREATE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['POST'])]
final readonly class CreateEnvironmentController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        return $this->action->handle(
            $request,
            'environment',
            'environment_create:'.$projectId,
            static fn ($form) => new CreateEnvironment(Uuid::v7()->toRfc4122(), $projectId, FormData::string($form, 'name'), true === $form->get('production')->getData()),
            'organization.environments.created',
            new RouteReference(RouteCollection::PROJECT->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]), EnvironmentType::class, [],
        );
    }
}
