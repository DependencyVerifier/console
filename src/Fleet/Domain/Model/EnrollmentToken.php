<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Fleet\Domain\Event\ReusableTokenApproved;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Enrollment token aggregate (docs/cadrage.md §6): shown once, stored hashed (TokenHasher), never read back.
 * - single: 1 hour, consumed by the first successful enrollment, its agent waits for an approval;
 * - reusable: expiry (90 days at most) and maximum uses mandatory, a default environment; `pending → approved →
 *   revoked`, and only the enrollments made once it is approved give approved agents.
 * Usable means not revoked, not expired and not used up; an active reusable token counts in
 * maxReusableEnrollmentTokens.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fleet_enrollment_token')]
#[ORM\Index(name: 'fleet_enrollment_token_team', columns: ['team_id'])]
class EnrollmentToken implements RecordsEvents
{
    use EventRecording;

    public const string PREFIX = 'dvet_';
    public const string SINGLE_VALIDITY = '+1 hour';
    public const string MAX_REUSABLE_VALIDITY = '+90 days';
    public const int MAX_USES = 10000;

    #[ORM\Column(length: 16, enumType: EnrollmentTokenStatus::class)]
    private EnrollmentTokenStatus $status = EnrollmentTokenStatus::Pending;

    #[ORM\Column]
    private int $uses = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $approvedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $environmentId,
        #[ORM\Column(length: 16, enumType: EnrollmentTokenKind::class)]
        private EnrollmentTokenKind $kind,
        #[ORM\Column(length: 128, unique: true)]
        private string $tokenHash,
        #[ORM\Column(type: 'uuid')]
        private Uuid $createdBy,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
        #[ORM\Column]
        private int $maxUses,
    ) {
    }

    public static function single(EnrollmentTokenId $id, Uuid $teamId, ?Uuid $environmentId, string $tokenHash, Uuid $createdBy, \DateTimeImmutable $now): self
    {
        return new self($id->value, $teamId, $environmentId, EnrollmentTokenKind::Single, $tokenHash, $createdBy, $now, $now->modify(self::SINGLE_VALIDITY), 1);
    }

    public static function reusable(EnrollmentTokenId $id, Uuid $teamId, Uuid $environmentId, string $tokenHash, Uuid $createdBy, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt, int $maxUses): self
    {
        if ($expiresAt <= $now || $expiresAt > $now->modify(self::MAX_REUSABLE_VALIDITY) || $maxUses < 1 || $maxUses > self::MAX_USES) {
            throw new InvalidEnrollmentTokenSettings();
        }

        return new self($id->value, $teamId, $environmentId, EnrollmentTokenKind::Reusable, $tokenHash, $createdBy, $now, $expiresAt, $maxUses);
    }

    public function id(): EnrollmentTokenId
    {
        return EnrollmentTokenId::of($this->id);
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function environmentId(): ?Uuid
    {
        return $this->environmentId;
    }

    public function kind(): EnrollmentTokenKind
    {
        return $this->kind;
    }

    public function isReusable(): bool
    {
        return EnrollmentTokenKind::Reusable === $this->kind;
    }

    public function status(): EnrollmentTokenStatus
    {
        return $this->status;
    }

    public function uses(): int
    {
        return $this->uses;
    }

    public function maxUses(): int
    {
        return $this->maxUses;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return EnrollmentTokenStatus::Revoked !== $this->status && $now < $this->expiresAt && $this->uses < $this->maxUses;
    }

    /**
     * Do its enrollments give approved agents? Only a reusable token approved by a human.
     */
    public function approvesAgents(): bool
    {
        return EnrollmentTokenStatus::Approved === $this->status;
    }

    /**
     * Says why a token cannot enroll (protocol: token_invalid, then token_expired).
     */
    public function assertUsableAt(\DateTimeImmutable $now): void
    {
        if (EnrollmentTokenStatus::Revoked === $this->status || $this->uses >= $this->maxUses) {
            throw new EnrollmentTokenInvalid();
        }

        if ($now >= $this->expiresAt) {
            throw new EnrollmentTokenExpired();
        }
    }

    /**
     * One more enrollment (checked and counted under the team lock).
     */
    public function consume(\DateTimeImmutable $now): void
    {
        $this->assertUsableAt($now);
        ++$this->uses;
    }

    public function approve(Uuid $by, \DateTimeImmutable $now): void
    {
        if (!$this->isReusable() || EnrollmentTokenStatus::Pending !== $this->status || !$this->isUsableAt($now)) {
            throw new EnrollmentTokenNotApprovable();
        }

        $this->status = EnrollmentTokenStatus::Approved;
        $this->approvedAt = $now;
        $this->approvedBy = $by;
        $this->recordThat(new ReusableTokenApproved($this->id->toRfc4122(), $this->teamId->toRfc4122()));
    }

    /**
     * Idempotent: revoking a revoked token changes nothing.
     */
    public function revoke(\DateTimeImmutable $now): void
    {
        if (EnrollmentTokenStatus::Revoked === $this->status) {
            return;
        }

        $this->status = EnrollmentTokenStatus::Revoked;
        $this->revokedAt = $now;
    }

    public function approvedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function approvedBy(): ?Uuid
    {
        return $this->approvedBy;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function createdBy(): Uuid
    {
        return $this->createdBy;
    }
}
