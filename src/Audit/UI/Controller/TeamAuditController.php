<?php

declare(strict_types=1);

namespace Audit\UI\Controller;

use Organization\Application\Query\Team\GetTeam;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The audit log of a team (`team.audit.view` on that team): the team comes from the route, never from the query.
 */
#[Route(path: '/teams/{teamId}/audit', name: RouteCollection::TEAM_AUDIT->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET'])]
final readonly class TeamAuditController
{
    public function __construct(private AuditLogPage $page, private QueryBus $queries)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        // The team's name for the header, from Organization's query (404 outside the team).
        $team = $this->queries->ask(new GetTeam($teamId));

        return $this->page->render($request, $teamId, 'audit/team.html.twig', ['nav_active' => 'team_audit', 'current_team' => $teamId, 'team' => $team]);
    }
}
