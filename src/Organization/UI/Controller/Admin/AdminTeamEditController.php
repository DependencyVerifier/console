<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Admin;

use Organization\Application\Command\Team\UpdateTeam;
use Organization\Application\Query\Team\GetTeam;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\TeamEditType;
use Shared\Application\Bus\QueryBus;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The "Edit" dialog of a row of /admin/teams: name and the 9 quotas (super admin).
 */
#[Route(path: '/admin/teams/{teamId}/edit', name: RouteCollection::ADMIN_TEAM_EDIT->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class AdminTeamEditController
{
    public const string DIALOG_PREFIX = 'edit-team-';

    public function __construct(private QueryBus $queries, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        $team = $this->queries->ask(new GetTeam($teamId));
        $quotas = [];
        foreach ($team->quotas as $quota) {
            $quotas[$quota->name] = $quota->limit;
        }

        $form = $this->forms->createNamed('team_edit', TeamEditType::class, ['name' => $team->name, 'quotas' => $quotas], ['csrf_token_id' => 'team_edit:'.$teamId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG_PREFIX.$teamId,
            'action.save',
            static fn (): UpdateTeam => new UpdateTeam($teamId, FormData::string($form, 'name'), FormData::nullableInts($form, 'quotas')),
            $this->redirect->withSuccessKey('organization.teams.updated'),
            new RouteReference(RouteCollection::ADMIN_TEAMS->prefixed()),
        );
    }
}
