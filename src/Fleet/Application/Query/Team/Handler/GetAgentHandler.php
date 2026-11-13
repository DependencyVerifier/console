<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team\Handler;

use Fleet\Application\Query\Team\GetAgent;
use Fleet\Application\Query\View\AgentRow;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\HostRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Symfony\Component\Uid\Uuid;

final readonly class GetAgentHandler implements QueryHandler
{
    public function __construct(private FleetAccess $access, private AgentRepository $agents, private HostRepository $hosts)
    {
    }

    public function __invoke(GetAgent $query): AgentRow
    {
        $agent = (Uuid::isValid($query->agentId) ? $this->agents->find(AgentId::fromString($query->agentId)) : null) ?? throw ResourceNotFound::of('agent');
        $this->access->view($agent->teamId()->toRfc4122());
        $runtime = $agent->runtime();

        return new AgentRow(
            $agent->id()->toString(), $agent->teamId()->toRfc4122(), $agent->status()->value, $agent->fingerprint(), $runtime->mode->value,
            $runtime->scope?->value, $runtime->composeProject, $runtime->version, $agent->hostId()->toString(),
            $this->hosts->find($agent->hostId())?->name() ?? '', $agent->enrolledAt(), $agent->lastSeenAt(),
            '', $agent->desiredConfig()?->toPayload(), $agent->appliedConfigVersion(),
        );
    }
}
