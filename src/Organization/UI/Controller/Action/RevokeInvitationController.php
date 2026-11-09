<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Invitation\RevokeInvitation;
use Organization\UI\Controller\RouteCollection;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/invitations/{invitationId}/revoke', name: RouteCollection::INVITATION_REVOKE->value, requirements: ['teamId' => Requirement::UUID, 'invitationId' => Requirement::UUID], methods: ['POST'])]
final readonly class RevokeInvitationController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $invitationId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'invitation_revoke:'.$invitationId,
            static fn () => new RevokeInvitation($invitationId),
            'organization.invitations.revoked',
            new RouteReference(RouteCollection::TEAM->prefixed(), ['teamId' => $teamId]),
        );
    }
}
