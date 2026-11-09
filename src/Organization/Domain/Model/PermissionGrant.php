<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Business history of roles and permissions, append-only, shown in the UI. The audit log is the security trace;
 * both are written in the same transaction.
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_permission_grant')]
#[ORM\Index(name: 'organization_permission_grant_team', columns: ['team_id', 'granted_at'])]
class PermissionGrant
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $projectId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $userId,
        #[ORM\Column(length: 32, enumType: GrantChange::class)]
        private GrantChange $change,
        #[ORM\Column(length: 64)]
        private string $value,
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $grantedBy,
        #[ORM\Column]
        private \DateTimeImmutable $grantedAt,
    ) {
    }

    public static function record(TeamId $teamId, ?ProjectId $projectId, Uuid $userId, GrantChange $change, string $value, ?Uuid $grantedBy, \DateTimeImmutable $now): self
    {
        return new self(Uuid::v7(), $teamId->value, $projectId?->value, $userId, $change, $value, $grantedBy, $now);
    }

    public function change(): GrantChange
    {
        return $this->change;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }
}
