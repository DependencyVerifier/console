<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Invitation\InviteMember;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\InviteType;
use Shared\UI\Action\FormData;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route(path: '/teams/{teamId}/invite', name: RouteCollection::TEAM_INVITE->value, requirements: ['teamId' => Requirement::UUID], methods: ['POST'])]
final readonly class InviteTeamMemberController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        return $this->action->handle(
            $request,
            'invite',
            'team_invite:'.$teamId,
            static fn ($form) => new InviteMember(Uuid::v7()->toRfc4122(), $teamId, null, FormData::string($form, 'email'), FormData::string($form, 'role')),
            'organization.invitations.sent',
            new RouteReference(RouteCollection::TEAM->prefixed(), ['teamId' => $teamId]), InviteType::class, [],
        );
    }
}
