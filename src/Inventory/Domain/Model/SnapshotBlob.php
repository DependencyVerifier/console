<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A snapshot as uploaded (gzip), checked against its digest and format, stored once per team: a team never learns
 * whether another one holds the same document. Never changed; deleted when no history references it any more.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_blob')]
#[ORM\UniqueConstraint(name: 'inventory_blob_digest', columns: ['team_id', 'digest'])]
class SnapshotBlob
{
    /**
     * @param resource|string $content gzip bytes (a stream once loaded by Doctrine)
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(length: 71)]
        private string $digest,
        #[ORM\Column(length: 64, enumType: MediaType::class)]
        private MediaType $mediaType,
        #[ORM\Column]
        private int $size,
        #[ORM\Column(type: 'blob')]
        private mixed $content,
        #[ORM\Column]
        private \DateTimeImmutable $storedAt,
    ) {
    }

    public static function store(Uuid $id, Uuid $teamId, Digest $digest, MediaType $mediaType, int $size, string $gzip, \DateTimeImmutable $now): self
    {
        return new self($id, $teamId, $digest->value, $mediaType, $size, $gzip, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function digest(): Digest
    {
        return Digest::of($this->digest);
    }

    public function mediaType(): MediaType
    {
        return $this->mediaType;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function storedAt(): \DateTimeImmutable
    {
        return $this->storedAt;
    }

    /**
     * The gzip bytes.
     */
    public function gzip(): string
    {
        if (\is_resource($this->content)) {
            $this->content = (string) stream_get_contents($this->content);
        }

        return \is_string($this->content) ? $this->content : '';
    }
}
