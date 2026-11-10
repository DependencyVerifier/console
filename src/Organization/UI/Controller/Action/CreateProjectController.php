<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Project\CreateProject;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Controller\Team\TeamPage;
use Organization\UI\Form\NameType;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * The "New project" dialog of the team page (overview and Projects tabs). CreateProject is the judge of the
 * permission and of the quota.
 */
#[Route(path: '/teams/{teamId}/projects/new', name: RouteCollection::PROJECT_CREATE->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class CreateProjectController
{
    public const string DIALOG = 'create-project';

    public function __construct(private TeamPage $page, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        // The form is served only to whom may submit it: 404 out of scope (GetTeam), 403 without the permission. The
        // command stays the judge of the submission.
        if (!$this->page->team($teamId)->can('project.create')) {
            throw new AccessDeniedException();
        }

        $form = $this->forms->createNamed('project', NameType::class, null, ['csrf_token_id' => 'project_create:'.$teamId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'organization.projects.create',
            static fn (): CreateProject => new CreateProject(Uuid::v7()->toRfc4122(), $teamId, FormData::string($form, 'name')),
            $this->redirect->withSuccessKey('organization.projects.created'),
            new RouteReference(RouteCollection::TEAM_PROJECTS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
