<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Project\CreateProject;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\NameType;
use Shared\UI\Action\FormData;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route(path: '/teams/{teamId}/projects', name: RouteCollection::PROJECT_CREATE->value, requirements: ['teamId' => Requirement::UUID], methods: ['POST'])]
final readonly class CreateProjectController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        return $this->action->handle(
            $request,
            'project',
            'project_create:'.$teamId,
            static fn ($form) => new CreateProject(Uuid::v7()->toRfc4122(), $teamId, FormData::string($form, 'name')),
            'organization.projects.created',
            new RouteReference(RouteCollection::TEAM->prefixed(), ['teamId' => $teamId]), NameType::class, [],
        );
    }
}
