<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Recipient\DetachRecipient;
use Notification\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A row menu action; the command judges on the project of the recipient.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/notifications/recipients/{subscriptionId}/detach', name: RouteCollection::RECIPIENT_DETACH->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID, 'subscriptionId' => Requirement::UUID], methods: ['POST'])]
final readonly class DetachRecipientController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId, string $subscriptionId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'recipient_detach:'.$subscriptionId,
            static fn () => new DetachRecipient($subscriptionId),
            'notification.recipients.detached',
            new RouteReference(RouteCollection::PROJECT_NOTIFICATIONS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]),
        );
    }
}
