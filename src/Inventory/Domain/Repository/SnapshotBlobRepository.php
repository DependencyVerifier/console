<?php

declare(strict_types=1);

namespace Inventory\Domain\Repository;

use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\SnapshotBlob;
use Symfony\Component\Uid\Uuid;

interface SnapshotBlobRepository
{
    public function find(Uuid $teamId, Digest $digest): ?SnapshotBlob;

    /**
     * Which of these digests the team already holds.
     *
     * @param list<string> $digests
     *
     * @return list<string>
     */
    public function storedAmong(Uuid $teamId, array $digests): array;

    public function add(SnapshotBlob $blob): void;

    /**
     * Blobs no history entry, no application, image or host references any more (their packages go with them).
     *
     * @return int the blobs deleted
     */
    public function deleteUnreferenced(int $limit): int;

    public function deleteOfTeam(Uuid $teamId): void;
}
