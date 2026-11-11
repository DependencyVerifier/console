<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Agent\Handler;

use Fleet\Application\Command\Agent\RevokeAgent;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Repository\AgentRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class RevokeAgentHandler implements CommandHandler
{
    public function __construct(private FleetAccess $access, private AgentRepository $agents, private AgentRevocation $revocation)
    {
    }

    public function __invoke(RevokeAgent $command): void
    {
        $agent = $this->agents->find(AgentId::fromString($command->agentId)) ?? throw ResourceNotFound::of('agent');
        $this->access->assert(Permission::HostRevoke, $agent->teamId()->toRfc4122());
        $this->revocation->revoke($agent);
    }
}
