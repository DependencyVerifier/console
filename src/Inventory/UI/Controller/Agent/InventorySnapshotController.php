<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Agent;

use Fleet\Infrastructure\Security\AgentUser;
use Inventory\Application\Command\Agent\StoreSnapshot;
use Inventory\Application\Query\Agent\IsSnapshotStored;
use Inventory\UI\Controller\RouteCollection;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Protocol\PayloadRefused;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Protocol §8.3: 201 when stored now, 200 when the team already held it. The body stays gzip (Content-Digest covers
 * the compressed bytes); Symfony never inflates it.
 */
#[AsController]
final readonly class InventorySnapshotController
{
    public function __construct(private CommandBus $commands, private QueryBus $queries)
    {
    }

    #[Route(path: '/agent/v1/inventories/{inventoryId}/snapshots/{digest}', name: RouteCollection::AGENT_SNAPSHOT->value, requirements: ['inventoryId' => Requirement::UUID, 'digest' => 'sha256:[0-9a-f]{64}'], methods: ['PUT'])]
    public function __invoke(Request $request, #[CurrentUser] AgentUser $agent, string $inventoryId, string $digest): Response
    {
        if ('gzip' !== $request->headers->get('Content-Encoding')) {
            throw PayloadRefused::invalid('A snapshot is sent with Content-Encoding: gzip.');
        }

        $stored = $this->queries->ask(new IsSnapshotStored($agent->agentId, $inventoryId, $digest));
        $this->commands->dispatch(new StoreSnapshot($agent->agentId, $inventoryId, $digest, (string) $request->headers->get('Content-Type'), $request->getContent()));

        return new Response('', $stored ? 200 : 201, ['Cache-Control' => 'no-store']);
    }
}
