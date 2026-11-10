<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Team;

use Organization\UI\Controller\RouteCollection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Overview tab of a team: key facts at once, quota gauges and their banners in a lazy frame, shortcuts.
 */
#[Route(path: '/teams/{teamId}', name: RouteCollection::TEAM->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET'])]
final readonly class TeamController
{
    public function __construct(private TeamPage $page)
    {
    }

    public function __invoke(string $teamId): Response
    {
        return $this->page->render($this->page->team($teamId), TeamPage::OVERVIEW);
    }
}
