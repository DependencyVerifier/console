<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Webhook\EditWebhook;
use Notification\Application\Query\ListWebhooks;
use Notification\Application\Query\View\WebhookRow;
use Notification\UI\Controller\RouteCollection;
use Notification\UI\Form\WebhookType;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Query\PageRequest;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The "Edit" dialog of a webhook: its name and URL (its secret stays).
 */
#[Route(path: '/teams/{teamId}/notifications/webhooks/{webhookId}/edit', name: RouteCollection::WEBHOOK_EDIT->value, requirements: ['teamId' => Requirement::UUID, 'webhookId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class EditWebhookController
{
    public const string DIALOG_PREFIX = 'edit-webhook-';

    public function __construct(private QueryBus $queries, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $webhookId): Response
    {
        $webhooks = $this->queries->ask(new ListWebhooks($teamId, new PageRequest(limit: PageRequest::MAX_LIMIT)))->items;
        $webhook = array_find($webhooks, static fn (WebhookRow $row): bool => $row->id === $webhookId) ?? throw new NotFoundHttpException();
        $form = $this->forms->createNamed('webhook', WebhookType::class, ['name' => $webhook->name, 'url' => $webhook->url], ['csrf_token_id' => 'webhook_edit:'.$webhookId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG_PREFIX.$webhookId,
            'action.save',
            static fn (): EditWebhook => new EditWebhook($webhookId, FormData::string($form, 'name'), FormData::string($form, 'url')),
            $this->redirect->withSuccessKey('notification.webhooks.updated'),
            new RouteReference(RouteCollection::TEAM_NOTIFICATIONS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
