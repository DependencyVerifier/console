<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Project\RenameProject;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\NameType;
use Shared\UI\Action\FormData;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/projects/{projectId}/rename', name: RouteCollection::PROJECT_RENAME->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['POST'])]
final readonly class RenameProjectController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        return $this->action->handle(
            $request,
            'project_name',
            'project_rename:'.$projectId,
            static fn ($form) => new RenameProject($projectId, FormData::string($form, 'name')),
            'organization.projects.renamed',
            new RouteReference(RouteCollection::PROJECT->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]), NameType::class, [],
        );
    }
}
