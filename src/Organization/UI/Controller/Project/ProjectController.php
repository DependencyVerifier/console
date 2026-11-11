<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Project;

use Organization\UI\Controller\RouteCollection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Overview section of a project: key facts and shortcuts.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}', name: RouteCollection::PROJECT->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET'])]
final readonly class ProjectController
{
    public function __construct(private ProjectPage $page)
    {
    }

    public function __invoke(string $teamId, string $projectId): Response
    {
        return $this->page->render($this->page->project($teamId, $projectId), ProjectPage::OVERVIEW);
    }
}
