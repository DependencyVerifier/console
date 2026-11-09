<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Organization\Domain\Event\InvitationIssued;
use Organization\Domain\Event\InvitationReissued;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * Invitation aggregate (decisions 10 and 11): always a link to accept, single-use hashed token, 7 days, resendable and
 * revocable. Stored status is pending, accepted or revoked; expiry is computed, never stored. One pending invitation
 * per email and target (partial unique index).
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_invitation')]
#[ORM\Index(name: 'organization_invitation_team', columns: ['team_id'])]
class Invitation implements RecordsEvents
{
    use EventRecording;

    public const string VALIDITY = '+7 days';
    private const string PENDING = 'pending';
    private const string ACCEPTED = 'accepted';
    private const string REVOKED = 'revoked';

    #[ORM\Column(length: 16)]
    private string $status = self::PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $acceptedBy = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(length: 254)]
        private string $email,
        #[ORM\Embedded(class: InvitationTarget::class, columnPrefix: false)]
        private InvitationTarget $target,
        #[ORM\Column(type: 'uuid')]
        private Uuid $invitedBy,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(length: 128, unique: true)]
        private string $tokenHash,
    ) {
        $this->issuedAt = $createdAt;
    }

    public static function issue(InvitationId $id, string $email, InvitationTarget $target, Uuid $invitedBy, #[\SensitiveParameter] string $plainToken, string $tokenHash, \DateTimeImmutable $now): self
    {
        $invitation = new self($id->value, self::normalize($email), $target, $invitedBy, $now, $tokenHash);
        $invitation->recordThat(new InvitationIssued($id->toString(), $invitation->email, $plainToken));

        return $invitation;
    }

    public static function normalize(string $email): string
    {
        $normalized = u($email)->trim()->lower()->toString();
        if (u($normalized)->length() > 254 || false === filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidInvitationEmail();
        }

        return $normalized;
    }

    public function id(): InvitationId
    {
        return InvitationId::of($this->id);
    }

    public function email(): string
    {
        return $this->email;
    }

    public function target(): InvitationTarget
    {
        return $this->target;
    }

    public function invitedBy(): Uuid
    {
        return $this->invitedBy;
    }

    public function issuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->issuedAt->modify(self::VALIDITY);
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt();
    }

    public function isPendingAt(\DateTimeImmutable $now): bool
    {
        return self::PENDING === $this->status && !$this->isExpiredAt($now);
    }

    public function isAccepted(): bool
    {
        return self::ACCEPTED === $this->status;
    }

    public function isRevoked(): bool
    {
        return self::REVOKED === $this->status;
    }

    /**
     * A new token and a new validity of 7 days; the previous link stops working.
     */
    public function reissue(#[\SensitiveParameter] string $plainToken, string $tokenHash, \DateTimeImmutable $now): void
    {
        if (self::PENDING !== $this->status) {
            throw new InvitationNotPending();
        }

        $this->tokenHash = $tokenHash;
        $this->issuedAt = $now;
        $this->recordThat(new InvitationReissued($this->id->toRfc4122(), $this->email, $plainToken));
    }

    public function revoke(): void
    {
        if (self::ACCEPTED === $this->status) {
            throw new InvitationNotPending();
        }

        $this->status = self::REVOKED;
    }

    public function accept(string $email, Uuid $userId, \DateTimeImmutable $now): void
    {
        if (!$this->isPendingAt($now)) {
            throw new InvitationNotPending();
        }

        if (self::normalize($email) !== $this->email) {
            throw new InvitationEmailMismatch();
        }

        $this->status = self::ACCEPTED;
        $this->acceptedBy = $userId;
    }

    public function acceptedBy(): Uuid
    {
        return $this->acceptedBy ?? throw new \LogicException('The invitation was not accepted.');
    }
}
