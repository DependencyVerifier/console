<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Member\RemoveTeamMember;
use Organization\UI\Controller\RouteCollection;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/members/{userId}/remove', name: RouteCollection::TEAM_MEMBER_REMOVE->value, requirements: ['teamId' => Requirement::UUID, 'userId' => Requirement::UUID], methods: ['POST'])]
final readonly class RemoveTeamMemberController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $userId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'team_member_remove:'.$userId,
            static fn () => new RemoveTeamMember($teamId, $userId),
            'organization.members.removed',
            new RouteReference(RouteCollection::TEAM_MEMBERS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
