<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Inventory's copy of what a human said of a host in Fleet (Fleet's HostLabelled): its display name and IP addresses,
 * kept apart from its facts since a host may be labelled before its first inventory.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_host_label')]
#[ORM\Index(name: 'inventory_host_label_team', columns: ['team_id'])]
class HostLabel
{
    /**
     * @param list<string> $addresses
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $hostId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $displayName,
        #[ORM\Column(type: 'json')]
        private array $addresses,
    ) {
    }

    /**
     * @param list<string> $addresses
     */
    public static function of(Uuid $hostId, Uuid $teamId, ?string $displayName, array $addresses): self
    {
        return new self($hostId, $teamId, $displayName, $addresses);
    }

    /**
     * @param list<string> $addresses
     */
    public function change(?string $displayName, array $addresses): void
    {
        $this->displayName = $displayName;
        $this->addresses = $addresses;
    }

    public function hostId(): Uuid
    {
        return $this->hostId;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    /**
     * @return list<string>
     */
    public function addresses(): array
    {
        return $this->addresses;
    }
}
