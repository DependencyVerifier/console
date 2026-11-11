<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Action;

use Fleet\Application\Command\Agent\RevokeAgent;
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
#[Route(path: '/teams/{teamId}/agents/{agentId}/revoke', name: RouteCollection::AGENT_REVOKE->value, requirements: ['teamId' => Requirement::UUID, 'agentId' => Requirement::UUID], methods: ['POST'])]
final readonly class RevokeAgentController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $agentId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'agent_revoke:'.$agentId,
            static fn () => new RevokeAgent($agentId),
            'fleet.agents.revoked',
            new RouteReference(RouteCollection::TEAM_AGENTS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
