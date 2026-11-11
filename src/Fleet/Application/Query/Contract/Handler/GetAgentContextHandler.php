<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Contract\Handler;

use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\EnrollmentTokenRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Fleet\AgentContext;
use Shared\Application\Contract\Fleet\GetAgentContext;
use Symfony\Component\Uid\Uuid;

/**
 * The caller holds an authenticated agent (its own id): no further authorization.
 */
final readonly class GetAgentContextHandler implements QueryHandler
{
    public function __construct(private AgentRepository $agents, private EnrollmentTokenRepository $tokens)
    {
    }

    public function __invoke(GetAgentContext $query): AgentContext
    {
        $agent = (Uuid::isValid($query->agentId) ? $this->agents->find(AgentId::fromString($query->agentId)) : null) ?? throw ResourceNotFound::of('agent');
        $runtime = $agent->runtime();

        return new AgentContext(
            $agent->id()->toString(),
            $agent->teamId()->toRfc4122(),
            $agent->hostId()->toString(),
            $agent->status()->value,
            $runtime->mode->value,
            $runtime->scope?->value,
            $runtime->composeProject,
            $this->tokens->find($agent->enrollmentTokenId())?->environmentId()?->toRfc4122(),
        );
    }
}
