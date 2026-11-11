<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Action;

use Inventory\Application\Command\Application\AttachApplication;
use Inventory\Application\Query\Team\GetApplication;
use Inventory\UI\Controller\RouteCollection;
use Inventory\UI\Form\AttachApplicationType;
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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The "Attach" dialog of an application: the environments of the projects where the actor holds
 * project.apps.assign. AttachApplication judges again.
 */
#[Route(path: '/teams/{teamId}/applications/{appId}/attach', name: RouteCollection::APPLICATION_ATTACH->value, requirements: ['teamId' => Requirement::UUID, 'appId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class AttachApplicationController
{
    public const string DIALOG = 'attach-application';

    public function __construct(private QueryBus $queries, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $appId): Response
    {
        $view = $this->queries->ask(new GetApplication($appId));
        if ([] === $view->assignable) {
            throw new AccessDeniedException();
        }

        $choices = [];
        foreach ($view->assignable as $environment) {
            $choices[$environment->projectName.' / '.$environment->name] = $environment->environmentId;
        }

        $form = $this->forms->createNamed('attach', AttachApplicationType::class, ['environment' => $view->app->environmentId], ['csrf_token_id' => 'application_attach:'.$appId, 'environments' => $choices]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'inventory.applications.attach',
            static fn (): AttachApplication => new AttachApplication($appId, FormData::string($form, 'environment')),
            $this->redirect->withSuccessKey('inventory.applications.attached'),
            new RouteReference(RouteCollection::APPLICATION->prefixed(), ['teamId' => $teamId, 'appId' => $appId]),
        );
    }
}
