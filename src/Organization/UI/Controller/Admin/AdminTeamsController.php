<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Admin;

use Omines\DataTablesBundle\DataTableFactory;
use Organization\Application\Command\Team\CreateTeam;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\DataTable\TeamsTable;
use Organization\UI\Form\TeamType;
use Shared\UI\Action\ActionForm;
use Shared\UI\Action\ActionResponder;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Teams of the instance (DataTable) and the creation form (the first TEAM_ADMIN is invited at once).
 */
#[Route(path: '/admin/teams', name: RouteCollection::ADMIN_TEAMS->value, methods: ['GET', 'POST'])]
final class AdminTeamsController extends AbstractController
{
    public function __construct(
        private readonly DataTableFactory $tables,
        private readonly FormFactoryInterface $forms,
        private readonly ActionResponder $responder,
        private readonly StreamOrRedirect $redirect,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $table = $this->tables->createFromType(TeamsTable::class)->handleRequest($request);
        if ($table->isCallback()) {
            return $table->getResponse();
        }

        $form = $this->forms->createNamed('team', TeamType::class);
        if ($request->isMethod('POST')) {
            return $this->responder->handle(
                $request,
                new ActionForm($form, ActionForm::PAGE_ALERTS, 'shared/frame/refusal_stream.html.twig'),
                static fn (): CreateTeam => new CreateTeam(Uuid::v7()->toRfc4122(), FormData::string($form, 'name'), FormData::string($form, 'firstAdminEmail'), Uuid::v7()->toRfc4122()),
                $this->redirect->withSuccessKey('organization.teams.created'),
                new RouteReference(RouteCollection::ADMIN_TEAMS->prefixed()),
            );
        }

        return $this->render('organization/admin/teams.html.twig', ['table' => $table, 'form' => $form->createView(), 'nav_active' => 'admin']);
    }
}
