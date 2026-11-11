<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Inventory\Domain\Model\SnapshotRecord;
use Inventory\Domain\Repository\SnapshotRecordRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSnapshotRecordRepository implements SnapshotRecordRepository
{
    public function __construct(private InventoryManager $manager)
    {
    }

    public function add(SnapshotRecord $record): void
    {
        $this->manager->get()->persist($record);
    }

    public function purgeBefore(Uuid $teamId, \DateTimeImmutable $before): int
    {
        return (int) $this->manager->get()->getConnection()->executeStatement(<<<'SQL'
            DELETE FROM inventory_snapshot s
            WHERE s.team_id = :team AND s.taken_at < :before
              AND EXISTS (
                  SELECT 1 FROM inventory_snapshot n
                  WHERE n.subject_type = s.subject_type AND n.subject_id = s.subject_id
                    AND (n.taken_at > s.taken_at OR (n.taken_at = s.taken_at AND n.id > s.id))
              )
            SQL, ['team' => $teamId->toRfc4122(), 'before' => $before->format('Y-m-d H:i:s')]);
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(SnapshotRecord::class, 's')->where('s.teamId = :team')
            ->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
