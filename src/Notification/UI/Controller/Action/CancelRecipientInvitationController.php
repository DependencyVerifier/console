<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Recipient\CancelRecipientInvitation;
use Notification\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A row menu action; the command judges on the project of the invitation.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/notifications/invitations/{invitationId}/cancel', name: RouteCollection::RECIPIENT_CANCEL->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID, 'invitationId' => Requirement::UUID], methods: ['POST'])]
final readonly class CancelRecipientInvitationController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId, string $invitationId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'recipient_cancel:'.$invitationId,
            static fn () => new CancelRecipientInvitation($invitationId),
            'notification.recipients.cancelled',
            new RouteReference(RouteCollection::PROJECT_NOTIFICATIONS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]),
        );
    }
}
