<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Team;

use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\DataTable\InvitationsTable;
use Organization\UI\DataTable\TeamMembersTable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Members tab (not for a TEAM_GUEST): the members, then the pending invitations for those who manage them.
 */
#[Route(path: '/teams/{teamId}/members', name: RouteCollection::TEAM_MEMBERS->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class TeamMembersController
{
    public function __construct(private TeamPage $page, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        $team = $this->page->team($teamId);
        $this->page->assertOpen($team, TeamPage::MEMBERS);
        $tables = ['members' => $this->tables->createFromType(TeamMembersTable::class, ['teamId' => $teamId])->handleRequest($request)];
        if ($team->can('team.members.manage')) {
            $tables['invitations'] = $this->tables->createFromType(InvitationsTable::class, ['teamId' => $teamId])->handleRequest($request);
        }

        return $this->page->render($team, TeamPage::MEMBERS, $tables);
    }
}
