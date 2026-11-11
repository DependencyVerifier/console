<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\OrderStatus;
use Fleet\Domain\Model\OrderType;
use Fleet\Domain\Repository\AgentOrderRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryOrders implements AgentOrderRepository
{
    /** @var array<string, AgentOrder> insertion order is creation order */
    public array $orders = [];

    public function find(AgentOrderId $id): ?AgentOrder
    {
        return $this->orders[$id->toString()] ?? null;
    }

    public function add(AgentOrder $order): void
    {
        $this->orders[$order->id()->toString()] = $order;
    }

    public function openOf(AgentId $agentId, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->orders, static fn (AgentOrder $o): bool => $o->agentId()->value->equals($agentId->value) && $o->status()->isOpen())), 0, $limit);
    }

    public function lastOf(AgentId $agentId, OrderType $type): ?AgentOrder
    {
        $last = null;
        foreach ($this->orders as $order) {
            if ($order->agentId()->value->equals($agentId->value) && $type === $order->type()) {
                $last = $order;
            }
        }

        return $last;
    }

    public function deliveredExpiredBefore(\DateTimeImmutable $before, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->orders, static fn (AgentOrder $o): bool => OrderStatus::Delivered === $o->status() && $o->expiresAt() <= $before)), 0, $limit);
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->orders = array_filter($this->orders, static fn (AgentOrder $x): bool => !$x->teamId()->equals($teamId));
    }
}
