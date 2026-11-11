<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Agent;

use Fleet\Application\Command\Agent\EnrollAgent;
use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Application\Protocol\RequestVerifier;
use Fleet\Application\Query\Agent\FindEnrolledAgent;
use Fleet\Infrastructure\Security\AgentRequestAuthenticator;
use Fleet\UI\Controller\RouteCollection;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Protocol\AgentPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Protocol §5: 201 for a new agent, 200 for a retry with the same token and key. Refusals become problem documents
 * (AgentApiExceptionSubscriber).
 */
#[AsController]
final readonly class AgentEnrollmentController
{
    public function __construct(private CommandBus $commands, private QueryBus $queries, private ClockInterface $clock)
    {
    }

    #[Route(path: AgentRequestAuthenticator::ENROLLMENT_PATH, name: RouteCollection::AGENT_ENROLL->value, methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $signed = AgentRequestAuthenticator::signedRequestOf($request);
        if (RequestVerifier::isBodyTooLarge($signed->body)) {
            throw new ProtocolRefusal(Problem::PayloadTooLarge);
        }

        $agentId = Uuid::v7()->toRfc4122();
        $this->commands->dispatch(new EnrollAgent($agentId, $signed));
        $answer = $this->queries->ask(new FindEnrolledAgent(AgentPayload::parse($signed->body)->string('public_key')));

        return new JsonResponse([
            'agent_id' => $answer->agentId,
            'status' => $answer->status,
            'fingerprint' => $answer->fingerprint,
            'console_key_id' => $answer->consoleKeyId,
            'next_poll_in' => $answer->nextPollIn,
            'server_time' => OrderSigner::time($this->clock->now()),
        ], $answer->agentId === $agentId ? 201 : 200, ['Cache-Control' => 'no-store']);
    }
}
