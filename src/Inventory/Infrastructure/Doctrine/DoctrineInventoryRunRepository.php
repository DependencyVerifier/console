<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Inventory\Domain\Model\InventoryRun;
use Inventory\Domain\Model\RunStatus;
use Inventory\Domain\Repository\InventoryRunRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInventoryRunRepository implements InventoryRunRepository
{
    public function __construct(private InventoryManager $manager)
    {
    }

    public function find(Uuid $id): ?InventoryRun
    {
        return $this->manager->get()->find(InventoryRun::class, $id);
    }

    public function add(InventoryRun $run): void
    {
        $this->manager->get()->persist($run);
    }

    public function openReceivedBefore(\DateTimeImmutable $before, int $limit): array
    {
        /** @var list<InventoryRun> */
        return $this->manager->get()->createQueryBuilder()
            ->select('r')->from(InventoryRun::class, 'r')
            ->where('r.status = :open')->andWhere('r.receivedAt < :before')
            ->setParameter('open', RunStatus::Open)->setParameter('before', $before)
            ->orderBy('r.receivedAt')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(InventoryRun::class, 'r')->where('r.teamId = :team')
            ->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
