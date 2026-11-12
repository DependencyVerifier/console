<?php

declare(strict_types=1);

namespace Exposure\UI\Controller\Team;

use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\GetExposureSummary;
use Exposure\UI\Chart\ExposureCharts;
use Exposure\UI\Controller\RouteCollection;
use Exposure\UI\DataTable\TeamFindingsTable;
use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\Controller\Project\ProjectPage;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The Findings section of a project: its dashboard, then its findings, filtered (by environment too).
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/findings', name: RouteCollection::PROJECT_FINDINGS->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class ProjectFindingsController
{
    public function __construct(private ProjectPage $page, private QueryBus $queries, private DataTableFactory $tables, private FindingsFilters $filters, private ExposureCharts $charts)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        $project = $this->page->project($teamId, $projectId);
        $criteria = $this->filters->criteria($request);
        $table = $this->tables->createFromType(TeamFindingsTable::class, ['teamId' => $teamId, 'scope' => ExposureScope::Project, 'id' => $projectId, 'criteria' => $criteria])->handleRequest($request);
        if ($table->isCallback()) {
            return $table->getResponse();
        }

        $summary = $this->queries->ask(new GetExposureSummary($teamId, ExposureScope::Project, $projectId));

        return $this->page->render($project, ProjectPage::FINDINGS, ['findings' => $table], [
            'summary' => $summary,
            'severity_chart' => $this->charts->severities($summary),
            'trend_chart' => $this->charts->trend($summary),
            'criteria' => $criteria,
            'environments' => $this->filters->environments($teamId, $projectId),
        ], 'exposure/project/findings.html.twig');
    }
}
