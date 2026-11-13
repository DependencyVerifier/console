<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Project;

use Omines\DataTablesBundle\DataTable;
use Organization\Application\Query\Project\GetProject;
use Organization\Application\Query\View\ProjectView;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/**
 * The sections of a project page (Overview, Environments, Members, Settings), listed under "Projects" in the sidebar.
 * GetProject is the judge of the scope (404); the project must belong to the team of the URL (404 too). A section or
 * a dialog the actor may not use answers 403, the commands staying the judges of the submissions.
 */
final readonly class ProjectPage
{
    public const string OVERVIEW = 'overview';
    public const string ENVIRONMENTS = 'environments';
    public const string MEMBERS = 'members';
    public const string SETTINGS = 'settings';
    /** Rendered by Inventory (its own template), listed here so the sidebar shows it. */
    public const string APPLICATIONS = 'applications';
    /** Rendered by Exposure (its own template), listed here so the sidebar shows it. */
    public const string FINDINGS = 'findings';
    /** Rendered by Notification (its own template), listed here so the sidebar shows it. */
    public const string NOTIFICATIONS = 'notifications';

    public function __construct(private QueryBus $queries, private Environment $twig)
    {
    }

    public function project(string $teamId, string $projectId): ProjectView
    {
        $project = $this->queries->ask(new GetProject($projectId));
        if ($project->teamId !== $teamId) {
            throw new NotFoundHttpException();
        }

        return $project;
    }

    public function assertCan(ProjectView $project, string $permission): void
    {
        if (!$project->can($permission)) {
            throw new AccessDeniedException();
        }
    }

    /**
     * The sections the actor may open, in their order: whoever sees the project sees its environments and members.
     *
     * @return list<string>
     */
    public static function tabsOf(ProjectView $project): array
    {
        $tabs = [self::OVERVIEW, self::ENVIRONMENTS, self::APPLICATIONS, self::FINDINGS, self::NOTIFICATIONS, self::MEMBERS];
        if ($project->can('project.edit') || $project->can('project.delete')) {
            $tabs[] = self::SETTINGS;
        }

        return $tabs;
    }

    public function assertOpen(ProjectView $project, string $tab): void
    {
        if (!\in_array($tab, self::tabsOf($project), true)) {
            throw new AccessDeniedException();
        }
    }

    /**
     * @param array<string, DataTable> $tables
     * @param array<string, mixed>     $context
     * @param string|null              $template another context's template for its section (default: Organization's)
     */
    public function render(ProjectView $project, string $tab, array $tables = [], array $context = [], ?string $template = null): Response
    {
        foreach ($tables as $table) {
            if ($table->isCallback()) {
                return $table->getResponse();
            }
        }

        return new Response($this->twig->render($template ?? \sprintf('organization/project/%s.html.twig', $tab), [
            ...$context,
            'project' => $project,
            'tab' => $tab,
            'tables' => $tables,
            'nav_active' => 'project_'.$tab,
            'current_team' => $project->teamId,
            'current_project' => ['id' => $project->id, 'name' => $project->name, 'tab' => $tab, 'tabs' => self::tabsOf($project)],
        ]));
    }
}
