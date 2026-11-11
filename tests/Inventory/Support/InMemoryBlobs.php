<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\SnapshotBlob;
use Inventory\Domain\Repository\SnapshotBlobRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryBlobs implements SnapshotBlobRepository
{
    /** @var list<SnapshotBlob> */
    public array $blobs = [];

    public int $collected = 0;

    public function find(Uuid $teamId, Digest $digest): ?SnapshotBlob
    {
        return array_find($this->blobs, static fn (SnapshotBlob $b): bool => $b->teamId()->equals($teamId) && $b->digest()->equals($digest));
    }

    public function storedAmong(Uuid $teamId, array $digests): array
    {
        return array_values(array_filter($digests, fn (string $d): bool => null !== $this->find($teamId, Digest::of($d))));
    }

    public function add(SnapshotBlob $blob): void
    {
        $this->blobs[] = $blob;
    }

    public function deleteUnreferenced(int $limit): int
    {
        return $this->collected;
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->blobs = array_values(array_filter($this->blobs, static fn (SnapshotBlob $b): bool => !$b->teamId()->equals($teamId)));
    }
}
