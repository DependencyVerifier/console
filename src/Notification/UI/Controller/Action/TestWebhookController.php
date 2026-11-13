<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Webhook\TestWebhook;
use Notification\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Posts a signed `test` request now; its outcome shows in the Last call column.
 */
#[Route(path: '/teams/{teamId}/notifications/webhooks/{webhookId}/test', name: RouteCollection::WEBHOOK_TEST->value, requirements: ['teamId' => Requirement::UUID, 'webhookId' => Requirement::UUID], methods: ['POST'])]
final readonly class TestWebhookController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $webhookId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'webhook_test:'.$webhookId,
            static fn () => new TestWebhook($webhookId),
            'notification.webhooks.tested',
            new RouteReference(RouteCollection::TEAM_NOTIFICATIONS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
