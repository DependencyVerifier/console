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

#[Route(path: '/teams/{teamId}/projects/{projectId}/invite', name: RouteCollection::PROJECT_INVITE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['POST'])]
final readonly class InviteProjectMemberController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        return $this->action->handle(
            $request,
            'invite',
            'project_invite:'.$projectId,
            static fn ($form) => new InviteMember(Uuid::v7()->toRfc4122(), $teamId, $projectId, FormData::string($form, 'email'), FormData::string($form, 'role')),
            'organization.invitations.sent',
            new RouteReference(RouteCollection::PROJECT->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]), InviteType::class, ['project' => true],
        );
    }
}
