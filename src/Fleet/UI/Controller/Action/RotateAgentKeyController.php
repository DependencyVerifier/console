<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Action;

use Fleet\Application\Command\Order\RotateAgentKey;
use Fleet\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/agents/{agentId}/rotate-key', name: RouteCollection::AGENT_ROTATE_KEY->value, requirements: ['teamId' => Requirement::UUID, 'agentId' => Requirement::UUID], methods: ['POST'])]
final readonly class RotateAgentKeyController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $agentId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'agent_rotate_key:'.$agentId,
            static fn () => new RotateAgentKey($agentId),
            'fleet.agents.key_rotation_queued',
            new RouteReference(RouteCollection::AGENT->prefixed(), ['teamId' => $teamId, 'agentId' => $agentId]),
        );
    }
}
