<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Member\ChangeProjectRole;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\RoleType;
use Shared\UI\Action\FormData;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/projects/{projectId}/members/{userId}/role', name: RouteCollection::PROJECT_MEMBER_ROLE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID, 'userId' => Requirement::UUID], methods: ['POST'])]
final readonly class ChangeProjectRoleController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId, string $userId): Response
    {
        return $this->action->handle(
            $request,
            'role',
            'project_member_role:'.$userId,
            static fn ($form) => new ChangeProjectRole($projectId, $userId, FormData::string($form, 'role')),
            'organization.members.role_changed',
            new RouteReference(RouteCollection::PROJECT_MEMBERS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]), RoleType::class, ['project' => true],
        );
    }
}
