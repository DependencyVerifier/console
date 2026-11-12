<?php

declare(strict_types=1);

namespace Exposure\UI\Controller\Team;

use Exposure\Application\Query\GetFinding;
use Exposure\UI\Controller\RouteCollection;
use Organization\UI\Controller\Team\TeamPage;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;

/**
 * A finding, in the team frame of Organization: what it is, where, since when, and the decisions on its risk.
 */
#[Route(path: '/teams/{teamId}/findings/{findingId}', name: RouteCollection::FINDING->value, requirements: ['teamId' => Requirement::UUID, 'findingId' => Requirement::UUID], methods: ['GET'])]
final readonly class FindingController
{
    public function __construct(private TeamPage $teams, private QueryBus $queries, private Environment $twig)
    {
    }

    public function __invoke(string $teamId, string $findingId): Response
    {
        $team = $this->teams->team($teamId);
        $view = $this->queries->ask(new GetFinding($teamId, $findingId));

        return new Response($this->twig->render('exposure/team/finding.html.twig', [
            'view' => $view,
            'finding' => $view->finding,
            'team' => $team,
            'tab' => 'applications',
            'tabs' => TeamPage::tabsOf($team),
            'tables' => [],
            'nav_active' => 'application' === $view->finding->subjectType ? 'team_applications' : 'team_hosts',
            'current_team' => $team->id,
        ]));
    }
}
