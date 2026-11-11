<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What the last inventory said about a host of Fleet (same id): its facts (OS, kernel, runtimes, Docker), and the
 * SBOM of its OS packages when an agent that sees the host sent one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_host')]
#[ORM\Index(name: 'inventory_host_team', columns: ['team_id'])]
class HostInventory
{
    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $facts = [];

    #[ORM\Column(length: 71, nullable: true)]
    private ?string $factsDigest = null;

    #[ORM\Column(length: 71, nullable: true)]
    private ?string $packagesDigest = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $lastInventoryId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $inventoriedAt = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $hostId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
    ) {
    }

    public static function of(Uuid $hostId, Uuid $teamId): self
    {
        return new self($hostId, $teamId);
    }

    public function hostId(): Uuid
    {
        return $this->hostId;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    /**
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        return $this->facts;
    }

    public function factsDigest(): ?Digest
    {
        return null === $this->factsDigest ? null : Digest::of($this->factsDigest);
    }

    public function packagesDigest(): ?Digest
    {
        return null === $this->packagesDigest ? null : Digest::of($this->packagesDigest);
    }

    public function lastInventoryId(): ?Uuid
    {
        return $this->lastInventoryId;
    }

    public function inventoriedAt(): ?\DateTimeImmutable
    {
        return $this->inventoriedAt;
    }

    /**
     * @param array<string, mixed> $facts    the host facts document
     * @param Digest|null          $packages the SBOM of the OS packages, null when this inventory had none (kept)
     */
    public function record(Uuid $inventoryId, array $facts, Digest $factsDigest, ?Digest $packages, \DateTimeImmutable $at): void
    {
        $this->facts = $facts;
        $this->factsDigest = $factsDigest->value;
        if (null !== $packages) {
            $this->packagesDigest = $packages->value;
        }

        $this->lastInventoryId = $inventoryId;
        $this->inventoriedAt = $at;
    }
}
