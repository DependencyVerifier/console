<?php

declare(strict_types=1);

namespace Fleet\Domain\Repository;

use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\OrderType;
use Symfony\Component\Uid\Uuid;

interface AgentOrderRepository
{
    public function find(AgentOrderId $id): ?AgentOrder;

    public function add(AgentOrder $order): void;

    /**
     * Queued and delivered orders of the agent, oldest first.
     *
     * @return list<AgentOrder>
     */
    public function openOf(AgentId $agentId, int $limit): array;

    /**
     * The newest order of that type for the agent (SCAN_NOW rate, SET_CONFIG in flight).
     */
    public function lastOf(AgentId $agentId, OrderType $type): ?AgentOrder;

    /**
     * Delivered orders whose result is late.
     *
     * @return list<AgentOrder>
     */
    public function deliveredExpiredBefore(\DateTimeImmutable $before, int $limit): array;

    /**
     * Every row of the team (DeleteTeam cascade).
     */
    public function deleteOfTeam(Uuid $teamId): void;
}
