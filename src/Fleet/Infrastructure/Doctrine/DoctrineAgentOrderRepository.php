<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Doctrine;

use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\OrderStatus;
use Fleet\Domain\Model\OrderType;
use Fleet\Domain\Repository\AgentOrderRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAgentOrderRepository implements AgentOrderRepository
{
    public function __construct(private FleetManager $manager)
    {
    }

    public function find(AgentOrderId $id): ?AgentOrder
    {
        return $this->manager->get()->find(AgentOrder::class, $id->value);
    }

    public function add(AgentOrder $order): void
    {
        $this->manager->get()->persist($order);
    }

    public function openOf(AgentId $agentId, int $limit): array
    {
        /** @var list<AgentOrder> */
        return $this->manager->get()->createQueryBuilder()
            ->select('o')->from(AgentOrder::class, 'o')
            ->where('o.agentId = :agent')->andWhere('o.status IN (:open)')
            ->setParameter('agent', $agentId->value, 'uuid')
            ->setParameter('open', [OrderStatus::Queued->value, OrderStatus::Delivered->value])
            ->orderBy('o.createdAt')->addOrderBy('o.id')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function lastOf(AgentId $agentId, OrderType $type): ?AgentOrder
    {
        return $this->manager->get()->getRepository(AgentOrder::class)->findOneBy(['agentId' => $agentId->value, 'type' => $type], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function deliveredExpiredBefore(\DateTimeImmutable $before, int $limit): array
    {
        /** @var list<AgentOrder> */
        return $this->manager->get()->createQueryBuilder()
            ->select('o')->from(AgentOrder::class, 'o')
            ->where('o.status = :delivered')->andWhere('o.expiresAt <= :before')
            ->setParameter('delivered', OrderStatus::Delivered)->setParameter('before', $before)
            ->orderBy('o.expiresAt')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(AgentOrder::class, 'x')->where('x.teamId = :team')
            ->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
