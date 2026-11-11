<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A machine of a team, created or found at enrollment from its identity (HostFacts::identityFor()). Its agents point
 * to it; the facts are those of the last enrollment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fleet_host')]
#[ORM\UniqueConstraint(name: 'fleet_host_identity', columns: ['team_id', 'identity_kind', 'identity_value'])]
class Host
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Embedded(class: HostIdentity::class, columnPrefix: false)]
        private HostIdentity $identity,
        #[ORM\Embedded(class: HostFacts::class, columnPrefix: false)]
        private HostFacts $facts,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function register(HostId $id, Uuid $teamId, HostIdentity $identity, HostFacts $facts, \DateTimeImmutable $now): self
    {
        return new self($id->value, $teamId, $identity, $facts, $now);
    }

    public function id(): HostId
    {
        return HostId::of($this->id);
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function identity(): HostIdentity
    {
        return $this->identity;
    }

    public function facts(): HostFacts
    {
        return $this->facts;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function refresh(HostFacts $facts, \DateTimeImmutable $now): void
    {
        $this->facts = $facts;
        $this->seen($now);
    }

    public function seen(\DateTimeImmutable $now): void
    {
        $this->lastSeenAt = $now;
    }
}
