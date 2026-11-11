<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Agent;

use Fleet\Application\Command\Agent\RecordAgentPoll;
use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Query\Agent\GetPollAnswer;
use Fleet\Infrastructure\Security\AgentUser;
use Fleet\UI\Controller\RouteCollection;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Protocol §6, behind the `agent` firewall (the signature, nonce and agent state are already checked).
 */
#[AsController]
final readonly class AgentPollController
{
    public function __construct(private CommandBus $commands, private QueryBus $queries, private ClockInterface $clock)
    {
    }

    #[Route(path: '/agent/v1/poll', name: RouteCollection::AGENT_POLL->value, methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] AgentUser $agent): JsonResponse
    {
        $this->commands->dispatch(new RecordAgentPoll($agent->agentId, $request->getContent()));
        $answer = $this->queries->ask(new GetPollAnswer($agent->agentId));

        return new JsonResponse([
            'status' => $answer->status,
            'orders' => $answer->orders,
            'next_poll_in' => $answer->nextPollIn,
            'server_time' => OrderSigner::time($this->clock->now()),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
