<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Order\Handler;

use Fleet\Application\Command\Order\UpgradeAgent;
use Fleet\Application\Port\AgentReleaseStore;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentMode;
use Fleet\Domain\Model\AgentNotUpgradable;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\OrderStatus;
use Fleet\Domain\Model\OrderType;
use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * Queues UPGRADE_AGENT to the latest verified release: for one agent (refused when it cannot run it), or for every
 * approved host agent of the team behind it. An agent with an upgrade already waiting gets no second one.
 */
final readonly class UpgradeAgentHandler implements CommandHandler
{
    public function __construct(
        private FleetAccess $access,
        private AgentRepository $agents,
        private AgentOrderRepository $orders,
        private AgentReleaseStore $releases,
        private CurrentActor $actor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpgradeAgent $command): void
    {
        $agents = null === $command->agentId
            ? $this->agents->ofTeam(Uuid::fromString($command->teamId))
            : [(Uuid::isValid($command->agentId) ? $this->agents->find(AgentId::fromString($command->agentId)) : null) ?? throw ResourceNotFound::of('agent')];
        foreach ($agents as $agent) {
            if ($agent->teamId()->toRfc4122() !== $command->teamId) {
                throw ResourceNotFound::of('agent');
            }
        }

        $this->access->assert(Permission::HostOrder, $command->teamId);
        $release = $this->releases->latest() ?? throw new AgentNotUpgradable();
        $now = $this->clock->now();
        foreach ($agents as $agent) {
            if (null === $command->agentId && !self::lags($agent, $release->version)) {
                continue;
            }

            $waiting = $this->orders->lastOf($agent->id(), OrderType::UpgradeAgent);
            if (null !== $waiting && OrderStatus::Queued === $waiting->status()) {
                continue;
            }

            $this->orders->add(AgentOrder::upgradeAgent(AgentOrderId::of(Uuid::v7()), $agent, $release->version, $this->actor->actor()->userId(), $now));
        }
    }

    private static function lags(Agent $agent, string $version): bool
    {
        return $agent->isApproved() && AgentMode::Host === $agent->runtime()->mode && version_compare($version, $agent->runtime()->version, '>');
    }
}
