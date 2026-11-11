<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Environment\CreateEnvironment;
use Organization\UI\Controller\Project\ProjectPage;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\EnvironmentType;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The "Add environment" dialog of the project page. CreateEnvironment is the judge of the permission and of the quota.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/environments/new', name: RouteCollection::ENVIRONMENT_CREATE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class CreateEnvironmentController
{
    public const string DIALOG = 'create-environment';

    public function __construct(private ProjectPage $page, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        $this->page->assertCan($this->page->project($teamId, $projectId), 'project.environments.manage');
        $form = $this->forms->createNamed('environment', EnvironmentType::class, null, ['csrf_token_id' => 'environment_create:'.$projectId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'organization.environments.create',
            static fn (): CreateEnvironment => new CreateEnvironment(Uuid::v7()->toRfc4122(), $projectId, FormData::string($form, 'name'), true === $form->get('production')->getData()),
            $this->redirect->withSuccessKey('organization.environments.created'),
            new RouteReference(RouteCollection::PROJECT_ENVIRONMENTS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]),
        );
    }
}
