<?php

declare(strict_types=1);

namespace Exposure\UI\Controller\Team;

use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\FindingCriteria;
use Exposure\UI\Controller\RouteCollection;
use Exposure\UI\DataTable\TeamFindingsTable;
use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\Controller\Team\TeamPage;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Vulnerability\GetAdvisory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;

/**
 * An advisory, as synced from OSV, and the findings of the team it causes (as far as the actor sees them, open or
 * not). A team page: the advisory itself is public, its findings are not.
 */
#[Route(path: '/teams/{teamId}/advisories/{advisoryId}', name: RouteCollection::ADVISORY->value, requirements: ['teamId' => Requirement::UUID, 'advisoryId' => '[A-Za-z0-9][A-Za-z0-9._:-]{0,99}'], methods: ['GET', 'POST'])]
final readonly class AdvisoryController
{
    public function __construct(private TeamPage $teams, private QueryBus $queries, private DataTableFactory $tables, private Environment $twig)
    {
    }

    public function __invoke(Request $request, string $teamId, string $advisoryId): Response
    {
        $team = $this->teams->team($teamId);
        $advisory = $this->queries->ask(new GetAdvisory($advisoryId)) ?? throw new NotFoundHttpException();
        $table = $this->tables->createFromType(TeamFindingsTable::class, ['teamId' => $teamId, 'scope' => ExposureScope::Team, 'criteria' => new FindingCriteria(status: 'all', advisory: $advisory->osvId)])
            ->handleRequest($request);
        if ($table->isCallback()) {
            return $table->getResponse();
        }

        return new Response($this->twig->render('exposure/team/advisory.html.twig', [
            'advisory' => $advisory,
            'team' => $team,
            'tab' => 'findings',
            'tabs' => TeamPage::tabsOf($team),
            'tables' => ['findings' => $table],
            'nav_active' => 'team_findings',
            'current_team' => $team->id,
        ]));
    }
}
