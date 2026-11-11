<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The history of a subject: which snapshot it had in which inventory. Kept snapshotRetentionDays, the latest of each
 * subject always.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_snapshot')]
#[ORM\Index(name: 'inventory_snapshot_subject', columns: ['subject_id', 'taken_at'])]
#[ORM\Index(name: 'inventory_snapshot_team', columns: ['team_id', 'taken_at'])]
class SnapshotRecord
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $inventoryId,
        #[ORM\Column(length: 16, enumType: SnapshotSubject::class)]
        private SnapshotSubject $subjectType,
        #[ORM\Column(type: 'uuid')]
        private Uuid $subjectId,
        #[ORM\Column(length: 71)]
        private string $digest,
        #[ORM\Column]
        private \DateTimeImmutable $takenAt,
    ) {
    }

    public static function take(Uuid $id, Uuid $teamId, Uuid $inventoryId, SnapshotSubject $type, Uuid $subjectId, Digest $digest, \DateTimeImmutable $at): self
    {
        return new self($id, $teamId, $inventoryId, $type, $subjectId, $digest->value, $at);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function inventoryId(): Uuid
    {
        return $this->inventoryId;
    }

    public function subjectType(): SnapshotSubject
    {
        return $this->subjectType;
    }

    public function subjectId(): Uuid
    {
        return $this->subjectId;
    }

    public function digest(): Digest
    {
        return Digest::of($this->digest);
    }

    public function takenAt(): \DateTimeImmutable
    {
        return $this->takenAt;
    }
}
