<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Project;

use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\NameType;
use Shared\UI\Action\ActionButtonType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Settings section of a project (`project.edit` or `project.delete`): the name, and the deletion behind a confirmation.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/settings', name: RouteCollection::PROJECT_SETTINGS->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET'])]
final readonly class ProjectSettingsController
{
    public function __construct(private ProjectPage $page, private FormFactoryInterface $forms)
    {
    }

    public function __invoke(string $teamId, string $projectId): Response
    {
        $project = $this->page->project($teamId, $projectId);
        $this->page->assertOpen($project, ProjectPage::SETTINGS);

        return $this->page->render($project, ProjectPage::SETTINGS, context: [
            'rename_form' => $this->forms->createNamed('project_name', NameType::class, ['name' => $project->name], ['csrf_token_id' => 'project_rename:'.$projectId])->createView(),
            'delete_form' => $this->forms->createNamed('action', ActionButtonType::class, null, ['csrf_token_id' => 'project_delete:'.$projectId])->createView(),
        ]);
    }
}
