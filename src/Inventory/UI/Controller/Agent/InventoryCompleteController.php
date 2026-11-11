<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Agent;

use Fleet\Application\Protocol\OrderSigner;
use Fleet\Infrastructure\Security\AgentUser;
use Inventory\Application\Command\Agent\CompleteInventory;
use Inventory\UI\Controller\RouteCollection;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Protocol\AgentPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Protocol §8.4: 202, the inventory is stored and processed asynchronously (409 missing_snapshots otherwise).
 */
#[AsController]
final readonly class InventoryCompleteController
{
    public function __construct(private CommandBus $commands, private ClockInterface $clock)
    {
    }

    #[Route(path: '/agent/v1/inventories/{inventoryId}/complete', name: RouteCollection::AGENT_COMPLETE->value, requirements: ['inventoryId' => Requirement::UUID], methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] AgentUser $agent, string $inventoryId): JsonResponse
    {
        AgentPayload::parse($request->getContent());
        $this->commands->dispatch(new CompleteInventory($agent->agentId, $inventoryId));

        return new JsonResponse(['inventory_id' => $inventoryId, 'server_time' => OrderSigner::time($this->clock->now())], 202, ['Cache-Control' => 'no-store']);
    }
}
