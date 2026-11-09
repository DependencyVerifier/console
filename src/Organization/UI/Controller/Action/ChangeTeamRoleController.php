<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Member\ChangeTeamRole;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\RoleType;
use Shared\UI\Action\FormData;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/members/{userId}/role', name: RouteCollection::TEAM_MEMBER_ROLE->value, requirements: ['teamId' => Requirement::UUID, 'userId' => Requirement::UUID], methods: ['POST'])]
final readonly class ChangeTeamRoleController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $userId): Response
    {
        return $this->action->handle(
            $request,
            'role',
            'team_member_role:'.$userId,
            static fn ($form) => new ChangeTeamRole($teamId, $userId, FormData::string($form, 'role')),
            'organization.members.role_changed',
            new RouteReference(RouteCollection::TEAM->prefixed(), ['teamId' => $teamId]), RoleType::class, [],
        );
    }
}
