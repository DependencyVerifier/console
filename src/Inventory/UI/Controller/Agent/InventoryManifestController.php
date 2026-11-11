<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Agent;

use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Query\Agent\Handler\GetPollAnswerHandler;
use Fleet\Infrastructure\Security\AgentUser;
use Inventory\Application\Command\Agent\SubmitManifest;
use Inventory\Application\Protocol\ManifestReader;
use Inventory\Application\Query\Agent\GetMissingSnapshots;
use Inventory\UI\Controller\RouteCollection;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Protocol\AgentPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Protocol §8.2, behind the `agent` firewall: the digests still to upload.
 */
#[AsController]
final readonly class InventoryManifestController
{
    public function __construct(private CommandBus $commands, private QueryBus $queries, private ClockInterface $clock)
    {
    }

    #[Route(path: '/agent/v1/inventories', name: RouteCollection::AGENT_MANIFEST->value, methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] AgentUser $agent): JsonResponse
    {
        $body = $request->getContent();
        $inventoryId = ManifestReader::inventoryId(AgentPayload::parse($body));
        $this->commands->dispatch(new SubmitManifest($agent->agentId, $inventoryId, $body));

        return new JsonResponse([
            'inventory_id' => $inventoryId,
            'missing' => $this->queries->ask(new GetMissingSnapshots($agent->agentId, $inventoryId)),
            'next_poll_in' => GetPollAnswerHandler::APPROVED_POLL,
            'server_time' => OrderSigner::time($this->clock->now()),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
