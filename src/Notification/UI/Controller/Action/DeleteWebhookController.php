<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Webhook\DeleteWebhook;
use Notification\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A row menu action; the command judges the scope (404) and the permission (403) on the webhook's team.
 */
#[Route(path: '/teams/{teamId}/notifications/webhooks/{webhookId}/delete', name: RouteCollection::WEBHOOK_DELETE->value, requirements: ['teamId' => Requirement::UUID, 'webhookId' => Requirement::UUID], methods: ['POST'])]
final readonly class DeleteWebhookController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $webhookId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'webhook_delete:'.$webhookId,
            static fn () => new DeleteWebhook($webhookId),
            'notification.webhooks.deleted',
            new RouteReference(RouteCollection::TEAM_NOTIFICATIONS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
