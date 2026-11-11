<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A container image of a team, identified by its Image ID (`sha256:…`, local builds included): inventoried once and
 * shared by every application that runs it (cadrage §6bis).
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_container_image')]
#[ORM\UniqueConstraint(name: 'inventory_container_image_id', columns: ['team_id', 'image_id'])]
class ContainerImage
{
    /**
     * @param array<string, mixed> $fields tags, repo_digests, provenance, base_image
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(length: 71)]
        private string $imageId,
        #[ORM\Column(type: 'json')]
        private array $fields,
        #[ORM\Column(length: 71)]
        private string $sbomDigest,
        #[ORM\Column]
        private \DateTimeImmutable $firstSeenAt,
        #[ORM\Column]
        private \DateTimeImmutable $lastSeenAt,
    ) {
    }

    public static function discover(Uuid $id, Uuid $teamId, Subject $subject, \DateTimeImmutable $at): self
    {
        $imageId = $subject->string('image_id');
        if (SubjectKind::ContainerImage !== $subject->kind || null === $imageId) {
            throw new InvalidInventory('Not a container image subject.');
        }

        return new self($id, $teamId, $imageId, $subject->fields, $subject->sbom->digest->value, $at, $at);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function imageId(): string
    {
        return $this->imageId;
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function sbomDigest(): Digest
    {
        return Digest::of($this->sbomDigest);
    }

    public function firstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function lastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function seen(Subject $subject, \DateTimeImmutable $at): void
    {
        $this->fields = $subject->fields;
        $this->sbomDigest = $subject->sbom->digest->value;
        $this->lastSeenAt = $at;
    }
}
