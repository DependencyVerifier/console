<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Admin;

use Organization\Application\Command\Team\DeleteTeam;
use Organization\UI\Controller\Action\OrganizationAction;
use Organization\UI\Controller\RouteCollection;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The "Delete" confirmation of a row of /admin/teams (field-less form, CSRF token id `team_delete:<team id>`).
 */
#[Route(path: '/admin/teams/{teamId}/delete', name: RouteCollection::ADMIN_TEAM_DELETE->value, requirements: ['teamId' => Requirement::UUID], methods: ['POST'])]
final readonly class DeleteTeamController
{
    public const string TOKEN_PREFIX = 'team_delete:';

    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            self::TOKEN_PREFIX.$teamId,
            static fn () => new DeleteTeam($teamId),
            'organization.teams.deleted',
            new RouteReference(RouteCollection::ADMIN_TEAMS->prefixed()),
        );
    }
}
