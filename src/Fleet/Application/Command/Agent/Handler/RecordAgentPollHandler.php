<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Agent\Handler;

use Fleet\Application\Command\Agent\RecordAgentPoll;
use Fleet\Application\Protocol\AgentPayload;
use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Application\Quota\FleetQuotas;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\OrderStatus;
use Fleet\Domain\Model\OrderType;
use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\HostRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The poll of an authenticated agent: an inactive agent comes back if maxAgents allows (team lock), the order results
 * are applied, a lagging configuration is queued again, and the queued orders are signed (20 at most).
 */
final readonly class RecordAgentPollHandler implements CommandHandler
{
    public const int MAX_ORDERS = 20;

    public function __construct(
        private AgentRepository $agents,
        private HostRepository $hosts,
        private AgentOrderRepository $orders,
        private FleetQuotas $quotas,
        private OrderSigner $signer,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordAgentPoll $command): void
    {
        $agentId = AgentId::fromString($command->agentId);
        $payload = AgentPayload::parse($command->body);
        $version = $payload->string('agent_version');
        $configVersion = $payload->int('config_version');
        $results = $payload->objects('order_results');

        $agent = $this->agents->find($agentId) ?? throw new ProtocolRefusal(Problem::UnknownAgent);
        if ($agent->isInactive()) {
            // The seat is counted under the team lock; this agent's own status only changes through its requests.
            $this->quotas->lock($agent->teamId());
            $this->quotas->assertAgentSeat($agent->teamId());
            $agent->reactivate();
        }

        $now = $this->clock->now();
        $agent->seen($version, $configVersion, $now);
        $this->hosts->find($agent->hostId())?->seen($now);

        foreach ($results as $result) {
            $this->finish($agent, $result, $now);
        }

        if (!$agent->isApproved()) {
            return;
        }

        $open = $this->orders->openOf($agentId, self::MAX_ORDERS);
        $lagging = $this->queueLaggingConfig($agent, $now);
        if (null !== $lagging && \count($open) < self::MAX_ORDERS) {
            $open[] = $lagging;
        }

        foreach ($open as $order) {
            if (OrderStatus::Queued === $order->status()) {
                $order->issue($now);
                $order->deliver($this->signer->sign($order));
            } else {
                $order->expireIfLate($now);
            }
        }
    }

    private function finish(Agent $agent, AgentPayload $result, \DateTimeImmutable $now): void
    {
        $orderId = $result->string('order_id');
        $status = OrderStatus::tryFrom($result->string('status'));
        $finishedAt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $result->string('finished_at'), new \DateTimeZone('UTC'));
        if (!Uuid::isValid($orderId) || null === $status || false === $finishedAt) {
            throw new ProtocolRefusal(Problem::InvalidPayload, 'An order result is malformed.');
        }

        $order = $this->orders->find(AgentOrderId::fromString($orderId));
        if (null !== $order && $order->agentId()->value->equals($agent->id()->value)) {
            $order->finish($status, $result->optionalString('reason'), $finishedAt, $now);
        }
    }

    /**
     * The agent reports an older configuration than the desired one and no SET_CONFIG is on its way: queue it again.
     */
    private function queueLaggingConfig(Agent $agent, \DateTimeImmutable $now): ?AgentOrder
    {
        $config = $agent->desiredConfig();
        if (null === $config || !$agent->lagsBehindConfig() || true === $this->orders->lastOf($agent->id(), OrderType::SetConfig)?->status()->isOpen()) {
            return null;
        }

        $order = AgentOrder::setConfig(AgentOrderId::of(Uuid::v7()), $agent, $config, null, $now);
        $this->orders->add($order);

        return $order;
    }
}
