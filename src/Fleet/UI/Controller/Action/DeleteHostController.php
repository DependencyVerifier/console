<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Action;

use Fleet\Application\Command\Host\DeleteHost;
use Fleet\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A row menu action (Turbo Stream); the command judges the scope (404) and the permission (403) on the object's team.
 */
#[Route(path: '/teams/{teamId}/hosts/{hostId}/delete', name: RouteCollection::HOST_DELETE->value, requirements: ['teamId' => Requirement::UUID, 'hostId' => Requirement::UUID], methods: ['POST'])]
final readonly class DeleteHostController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $hostId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'host_delete:'.$hostId,
            static fn () => new DeleteHost($hostId),
            'fleet.hosts.deleted',
            new RouteReference(RouteCollection::TEAM_HOSTS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
