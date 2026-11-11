<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\SnapshotBlob;
use Inventory\Domain\Repository\SnapshotBlobRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSnapshotBlobRepository implements SnapshotBlobRepository
{
    /** A blob uploaded for an inventory still open (1 hour at most) is never collected. */
    private const string GRACE = '2 hours';

    public function __construct(private InventoryManager $manager)
    {
    }

    public function find(Uuid $teamId, Digest $digest): ?SnapshotBlob
    {
        return $this->manager->get()->getRepository(SnapshotBlob::class)->findOneBy(['teamId' => $teamId, 'digest' => $digest->value]);
    }

    public function storedAmong(Uuid $teamId, array $digests): array
    {
        if ([] === $digests) {
            return [];
        }

        /** @var list<string> */
        return $this->manager->get()->getConnection()->fetchFirstColumn(
            'SELECT digest FROM inventory_blob WHERE team_id = :team AND digest IN (:digests)',
            ['team' => $teamId->toRfc4122(), 'digests' => $digests],
            ['digests' => ArrayParameterType::STRING],
        );
    }

    public function add(SnapshotBlob $blob): void
    {
        $this->manager->get()->persist($blob);
    }

    public function deleteUnreferenced(int $limit): int
    {
        return (int) $this->manager->get()->getConnection()->executeStatement(<<<'SQL'
            DELETE FROM inventory_blob WHERE id IN (
                SELECT b.id FROM inventory_blob b
                WHERE b.stored_at < NOW() - CAST(:grace AS INTERVAL)
                  AND NOT EXISTS (SELECT 1 FROM inventory_snapshot s WHERE s.team_id = b.team_id AND s.digest = b.digest)
                  AND NOT EXISTS (SELECT 1 FROM inventory_app_instance a WHERE a.team_id = b.team_id AND a.sbom_digest = b.digest)
                  AND NOT EXISTS (SELECT 1 FROM inventory_container_image i WHERE i.team_id = b.team_id AND i.sbom_digest = b.digest)
                  AND NOT EXISTS (SELECT 1 FROM inventory_host h WHERE h.team_id = b.team_id AND b.digest IN (h.facts_digest, h.packages_digest))
                LIMIT :limit
            )
            SQL, ['grace' => self::GRACE, 'limit' => $limit]);
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->manager->get()->getConnection()->executeStatement('DELETE FROM inventory_blob WHERE team_id = :team', ['team' => $teamId->toRfc4122()]);
    }
}
