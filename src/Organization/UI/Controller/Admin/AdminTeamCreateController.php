<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Admin;

use Organization\Application\Command\Team\CreateTeam;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\TeamType;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The "Create a team" dialog of /admin/teams: the form and its submission (the first team admin is invited).
 */
#[Route(path: '/admin/teams/new', name: RouteCollection::ADMIN_TEAM_CREATE->value, methods: ['GET', 'POST'])]
final readonly class AdminTeamCreateController
{
    public const string DIALOG = 'create-team';

    public function __construct(private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->forms->createNamed('team', TeamType::class);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'organization.teams.create',
            static fn (): CreateTeam => new CreateTeam(Uuid::v7()->toRfc4122(), FormData::string($form, 'name'), FormData::string($form, 'firstAdminEmail'), Uuid::v7()->toRfc4122()),
            $this->redirect->withSuccessKey('organization.teams.created'),
            new RouteReference(RouteCollection::ADMIN_TEAMS->prefixed()),
        );
    }
}
