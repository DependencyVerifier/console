<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Agent\Handler;

use Fleet\Application\Command\Agent\ApproveAgent;
use Fleet\Application\Quota\FleetQuotas;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Repository\AgentRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;

final readonly class ApproveAgentHandler implements CommandHandler
{
    public function __construct(private FleetAccess $access, private AgentRepository $agents, private FleetQuotas $quotas, private CurrentActor $actor, private ClockInterface $clock)
    {
    }

    public function __invoke(ApproveAgent $command): void
    {
        $agent = $this->agents->find(AgentId::fromString($command->agentId)) ?? throw ResourceNotFound::of('agent');
        $this->access->assert(Permission::HostApprove, $agent->teamId()->toRfc4122());
        $this->quotas->lock($agent->teamId());
        $this->quotas->assertAgentsWithinLimit($agent->teamId());
        $agent->approve($this->actor->actor()->userId(), $this->clock->now());
    }
}
