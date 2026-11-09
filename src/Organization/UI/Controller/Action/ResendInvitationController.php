<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Invitation\ResendInvitation;
use Organization\UI\Controller\RouteCollection;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/invitations/{invitationId}/resend', name: RouteCollection::INVITATION_RESEND->value, requirements: ['teamId' => Requirement::UUID, 'invitationId' => Requirement::UUID], methods: ['POST'])]
final readonly class ResendInvitationController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $invitationId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'invitation_resend:'.$invitationId,
            static fn () => new ResendInvitation($invitationId),
            'organization.invitations.resent',
            new RouteReference(RouteCollection::TEAM->prefixed(), ['teamId' => $teamId]),
        );
    }
}
