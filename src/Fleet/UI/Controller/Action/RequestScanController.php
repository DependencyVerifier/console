<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Action;

use Fleet\Application\Command\Order\RequestScan;
use Fleet\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route(path: '/teams/{teamId}/agents/{agentId}/scan', name: RouteCollection::AGENT_SCAN->value, requirements: ['teamId' => Requirement::UUID, 'agentId' => Requirement::UUID], methods: ['POST'])]
final readonly class RequestScanController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $agentId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'agent_scan:'.$agentId,
            static fn () => new RequestScan(Uuid::v7()->toRfc4122(), $agentId),
            'fleet.agents.scan_requested',
            new RouteReference(RouteCollection::AGENT->prefixed(), ['teamId' => $teamId, 'agentId' => $agentId]),
        );
    }
}
