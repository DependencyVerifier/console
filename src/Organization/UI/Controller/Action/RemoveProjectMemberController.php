<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Member\RemoveProjectMember;
use Organization\UI\Controller\RouteCollection;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/projects/{projectId}/members/{userId}/remove', name: RouteCollection::PROJECT_MEMBER_REMOVE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID, 'userId' => Requirement::UUID], methods: ['POST'])]
final readonly class RemoveProjectMemberController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId, string $userId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'project_member_remove:'.$userId,
            static fn () => new RemoveProjectMember($projectId, $userId),
            'organization.members.removed',
            new RouteReference(RouteCollection::PROJECT_MEMBERS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]),
        );
    }
}
