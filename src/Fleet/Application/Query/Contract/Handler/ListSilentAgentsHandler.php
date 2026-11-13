<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Contract\Handler;

use Fleet\Domain\Model\Agent;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\HostRepository;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Fleet\ListSilentAgents;
use Shared\Application\Contract\Fleet\SilentAgent;

final readonly class ListSilentAgentsHandler implements QueryHandler
{
    public function __construct(private AgentRepository $agents, private HostRepository $hosts)
    {
    }

    /**
     * @return list<SilentAgent>
     */
    public function __invoke(ListSilentAgents $query): array
    {
        return array_map(fn (Agent $agent): SilentAgent => new SilentAgent(
            $agent->id()->toString(),
            $agent->teamId()->toRfc4122(),
            $this->hosts->find($agent->hostId())?->name() ?? '',
            $agent->lastSeenAt(),
        ), $this->agents->approvedSilentSince($query->since, $query->limit));
    }
}
