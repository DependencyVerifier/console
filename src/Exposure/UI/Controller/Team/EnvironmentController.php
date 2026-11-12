<?php

declare(strict_types=1);

namespace Exposure\UI\Controller\Team;

use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\GetExposureSummary;
use Exposure\UI\Chart\ExposureCharts;
use Exposure\UI\Controller\RouteCollection;
use Exposure\UI\DataTable\TeamFindingsTable;
use Inventory\UI\DataTable\ApplicationsTable;
use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\Controller\Project\ProjectPage;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The page of an environment, in its project's frame: its dashboard, its applications (Inventory) and its findings.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/environments/{environmentId}', name: RouteCollection::ENVIRONMENT->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID, 'environmentId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class EnvironmentController
{
    public function __construct(private ProjectPage $page, private QueryBus $queries, private DataTableFactory $tables, private FindingsFilters $filters, private ExposureCharts $charts)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId, string $environmentId): Response
    {
        $project = $this->page->project($teamId, $projectId);
        $environment = array_find($this->filters->environments($teamId, $projectId), static fn (TeamEnvironment $e): bool => $e->environmentId === $environmentId)
            ?? throw new NotFoundHttpException();
        $criteria = $this->filters->criteria($request);
        $tables = [
            'applications' => $this->tables->createFromType(ApplicationsTable::class, ['teamId' => $teamId, 'environmentId' => $environmentId])->handleRequest($request),
            'findings' => $this->tables->createFromType(TeamFindingsTable::class, ['teamId' => $teamId, 'scope' => ExposureScope::Environment, 'id' => $environmentId, 'criteria' => $criteria])->handleRequest($request),
        ];
        foreach ($tables as $table) {
            if ($table->isCallback()) {
                return $table->getResponse();
            }
        }

        $summary = $this->queries->ask(new GetExposureSummary($teamId, ExposureScope::Environment, $environmentId));

        return $this->page->render($project, ProjectPage::ENVIRONMENTS, $tables, [
            'environment' => $environment,
            'summary' => $summary,
            'severity_chart' => $this->charts->severities($summary),
            'trend_chart' => $this->charts->trend($summary),
            'criteria' => $criteria,
        ], 'exposure/project/environment.html.twig');
    }
}
