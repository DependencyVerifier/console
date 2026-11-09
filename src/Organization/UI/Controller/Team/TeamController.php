<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Team;

use Omines\DataTablesBundle\DataTableFactory;
use Organization\Application\Query\Team\GetTeam;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\DataTable\InvitationsTable;
use Organization\UI\DataTable\ProjectsTable;
use Organization\UI\DataTable\TeamMembersTable;
use Organization\UI\Form\InviteType;
use Organization\UI\Form\NameType;
use Shared\Application\Bus\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Team page: header at once (GetTeam is the judge: 404 out of scope), quota gauges in a lazy frame, members,
 * invitations and projects as server-side DataTables drawn from this same URL.
 */
#[Route(path: '/teams/{teamId}', name: RouteCollection::TEAM->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final class TeamController extends AbstractController
{
    public function __construct(private readonly QueryBus $queries, private readonly DataTableFactory $tables, private readonly FormFactoryInterface $forms)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        $team = $this->queries->ask(new GetTeam($teamId));
        $tables = ['projects' => $this->tables->createFromType(ProjectsTable::class, ['teamId' => $teamId])->handleRequest($request)];
        if ('team_guest' !== $team->role) {
            $tables['members'] = $this->tables->createFromType(TeamMembersTable::class, ['teamId' => $teamId])->handleRequest($request);
        }

        if ($team->can('team.members.manage')) {
            $tables['invitations'] = $this->tables->createFromType(InvitationsTable::class, ['teamId' => $teamId])->handleRequest($request);
        }

        foreach ($tables as $table) {
            if ($table->isCallback()) {
                return $table->getResponse();
            }
        }

        return $this->render('organization/team/show.html.twig', [
            'team' => $team,
            'tables' => $tables,
            'nav_active' => 'team',
            'current_team' => $teamId,
            'invite_form' => $this->forms->createNamed('invite', InviteType::class, null, ['csrf_token_id' => 'team_invite:'.$teamId])->createView(),
            'project_form' => $this->forms->createNamed('project', NameType::class, null, ['csrf_token_id' => 'project_create:'.$teamId])->createView(),
            'rename_form' => $this->forms->createNamed('team_name', NameType::class, ['name' => $team->name], ['csrf_token_id' => 'team_rename:'.$teamId, 'label_key' => 'organization.field.team_name'])->createView(),
        ]);
    }
}
