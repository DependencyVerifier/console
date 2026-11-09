<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Admin;

use Organization\Application\Command\Team\UpdateTeamQuotas;
use Organization\Application\Query\Team\GetTeam;
use Organization\UI\Controller\RouteCollection;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/admin/teams/{teamId}/quotas', name: RouteCollection::ADMIN_TEAM_QUOTAS->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class AdminTeamQuotasController
{
    public function __construct(private QueryBus $queries, private QuotasPage $page, private UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        $team = $this->queries->ask(new GetTeam($teamId));
        $current = [];
        foreach ($team->quotas as $quota) {
            $current[$quota->name] = $quota->limit;
        }

        return $this->page->handle(
            $request,
            'team_quotas:'.$teamId,
            $current,
            static fn (array $values): UpdateTeamQuotas => new UpdateTeamQuotas($teamId, $values),
            $this->urls->generate(RouteCollection::ADMIN_TEAMS->prefixed()),
            ['title' => $team->name, 'subtitle_key' => 'organization.quotas.team'],
        );
    }
}
