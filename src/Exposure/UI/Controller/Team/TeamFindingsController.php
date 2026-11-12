<?php

declare(strict_types=1);

namespace Exposure\UI\Controller\Team;

use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\GetExposureSummary;
use Exposure\UI\Chart\ExposureCharts;
use Exposure\UI\Controller\RouteCollection;
use Exposure\UI\DataTable\TeamFindingsTable;
use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\Controller\Team\TeamPage;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;

/**
 * The Findings section of a team: its dashboard, then every finding the actor sees, filtered.
 */
#[Route(path: '/teams/{teamId}/findings', name: RouteCollection::TEAM_FINDINGS->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class TeamFindingsController
{
    public function __construct(
        private TeamPage $teams,
        private QueryBus $queries,
        private DataTableFactory $tables,
        private FindingsFilters $filters,
        private ExposureCharts $charts,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        $team = $this->teams->team($teamId);
        $criteria = $this->filters->criteria($request);
        $table = $this->tables->createFromType(TeamFindingsTable::class, ['teamId' => $teamId, 'scope' => ExposureScope::Team, 'criteria' => $criteria])->handleRequest($request);
        if ($table->isCallback()) {
            return $table->getResponse();
        }

        $summary = $this->queries->ask(new GetExposureSummary($teamId));

        return new Response($this->twig->render('exposure/team/findings.html.twig', [
            'summary' => $summary,
            'severity_chart' => $this->charts->severities($summary),
            'trend_chart' => $this->charts->trend($summary),
            'criteria' => $criteria,
            'projects' => $this->filters->projects($teamId),
            'team' => $team,
            'tab' => 'findings',
            'tabs' => TeamPage::tabsOf($team),
            'tables' => ['findings' => $table],
            'nav_active' => 'team_findings',
            'current_team' => $team->id,
        ]));
    }
}
