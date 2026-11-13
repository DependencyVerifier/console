<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Agent;

use Fleet\Application\Command\Agent\RecordKeyRotation;
use Fleet\Infrastructure\Security\AgentUser;
use Fleet\UI\Controller\RouteCollection;
use Shared\Application\Bus\CommandBus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Protocol §5.3: the new key of an agent executing ROTATE_KEY, in a request signed by its current key.
 */
final readonly class AgentKeyRotationController
{
    public function __construct(private CommandBus $commands)
    {
    }

    #[Route(path: '/agent/v1/key-rotations', name: RouteCollection::AGENT_KEY_ROTATION->value, methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] AgentUser $agent): JsonResponse
    {
        $this->commands->dispatch(new RecordKeyRotation($agent->agentId, $request->getContent()));

        return new JsonResponse(['rotated' => true], 200, ['Cache-Control' => 'no-store']);
    }
}
