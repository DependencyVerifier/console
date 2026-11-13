<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Action;

use Fleet\Application\Command\Order\UpgradeAgent;
use Fleet\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * "Upgrade" on an agent page, or "Upgrade the host agents" on the list: UpgradeAgent judges the scope, host.order and
 * whether the agent can run the release.
 */
#[Route(path: '/teams/{teamId}/agents/{agentId}/upgrade', name: RouteCollection::AGENT_UPGRADE->value, requirements: ['teamId' => Requirement::UUID, 'agentId' => Requirement::UUID], methods: ['POST'])]
#[Route(path: '/teams/{teamId}/agents/upgrade', name: RouteCollection::TEAM_AGENTS_UPGRADE->value, requirements: ['teamId' => Requirement::UUID], methods: ['POST'])]
final readonly class UpgradeAgentController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, ?string $agentId = null): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'agent_upgrade:'.($agentId ?? $teamId),
            static fn () => new UpgradeAgent($teamId, $agentId),
            null === $agentId ? 'fleet.agents.upgrades_queued' : 'fleet.agents.upgrade_queued',
            null === $agentId
                ? new RouteReference(RouteCollection::TEAM_AGENTS->prefixed(), ['teamId' => $teamId])
                : new RouteReference(RouteCollection::AGENT->prefixed(), ['teamId' => $teamId, 'agentId' => $agentId]),
        );
    }
}
