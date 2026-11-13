<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Action;

use Inventory\Application\Command\Application\DocumentApplication;
use Inventory\Application\Query\Team\GetApplication;
use Inventory\UI\Controller\RouteCollection;
use Inventory\UI\Form\ApplicationLinksType;
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
 * The "Edit links" dialog of an application. DocumentApplication judges again.
 */
#[Route(path: '/teams/{teamId}/applications/{appId}/links', name: RouteCollection::APPLICATION_LINKS->value, requirements: ['teamId' => Requirement::UUID, 'appId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class DocumentApplicationController
{
    public const string DIALOG = 'application-links';

    public function __construct(private QueryBus $queries, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $appId): Response
    {
        $view = $this->queries->ask(new GetApplication($appId));
        if (!$view->mayDocument) {
            throw new AccessDeniedException();
        }

        $form = $this->forms->createNamed('links', ApplicationLinksType::class, ['links' => ApplicationLinksType::text($view->app->links)], ['csrf_token_id' => 'application_links:'.$appId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'inventory.applications.save_links',
            static fn (): DocumentApplication => new DocumentApplication($appId, ApplicationLinksType::links(FormData::nullableString($form, 'links'))),
            $this->redirect->withSuccessKey('inventory.applications.links_saved'),
            new RouteReference(RouteCollection::APPLICATION->prefixed(), ['teamId' => $teamId, 'appId' => $appId]),
        );
    }
}
