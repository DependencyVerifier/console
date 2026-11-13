<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Notification\Domain\Event\RecipientInvited;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * An email a manager wants told of the notifications of a project. Nothing exists for it but this invitation (no
 * account, no membership) until the holder of the address follows its link (7 days, hashed single-use token): adding
 * an address reveals nothing of the accounts of the instance and squats none. One pending per project and address.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_recipient_invitation')]
#[ORM\UniqueConstraint(name: 'notification_recipient_invitation_token', columns: ['token_hash'])]
#[ORM\Index(name: 'notification_recipient_invitation_project', columns: ['team_id', 'project_id', 'status'])]
class RecipientInvitation implements RecordsEvents
{
    use EventRecording;

    public const int VALIDITY_DAYS = 7;
    public const int MAX_EMAIL = 254;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $projectId,
        #[ORM\Column(length: 254)]
        private string $email,
        #[ORM\Column(length: 16, enumType: Frequency::class)]
        private Frequency $frequency,
        #[ORM\Column(type: 'uuid')]
        private Uuid $invitedBy,
        #[ORM\Column(length: 255)]
        private string $tokenHash,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
        #[ORM\Column(length: 16, enumType: RecipientInvitationStatus::class)]
        private RecipientInvitationStatus $status,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $closedAt = null,
    ) {
    }

    public static function issue(Uuid $id, Uuid $teamId, Uuid $projectId, string $email, Frequency $frequency, Uuid $invitedBy, #[\SensitiveParameter] string $plainToken, string $tokenHash, \DateTimeImmutable $now): self
    {
        if (Frequency::Off === $frequency) {
            throw new InvalidNotificationSetting('A recipient hears at once or in a digest.');
        }

        $invitation = new self($id, $teamId, $projectId, self::normalize($email), $frequency, $invitedBy, $tokenHash, $now, RecipientInvitationStatus::Pending, $now);
        $invitation->resend($plainToken, $tokenHash, $now);

        return $invitation;
    }

    /**
     * Trimmed, lower-cased, of the shape `local@domain.tld`; Identity checks it fully when an account is made of it.
     */
    public static function normalize(string $email): string
    {
        $address = u($email)->trim()->lower();
        if ($address->length() > self::MAX_EMAIL || [] === $address->match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/')) {
            throw new InvalidNotificationSetting('An email address.');
        }

        return $address->toString();
    }

    /**
     * A new link for a pending invitation: the former one no longer works, a new week starts.
     */
    public function resend(#[\SensitiveParameter] string $plainToken, string $tokenHash, \DateTimeImmutable $now): void
    {
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $now->modify(\sprintf('+%d days', self::VALIDITY_DAYS));
        $this->recordThat(new RecipientInvited($this->id->toRfc4122(), $this->teamId->toRfc4122(), $this->projectId->toRfc4122(), $this->email, $plainToken));
    }

    /**
     * Its link followed in time, by the holder of the address.
     *
     * @throws RecipientLinkInvalid
     */
    public function assertUsable(\DateTimeImmutable $now): void
    {
        if (!$this->isPendingAt($now)) {
            throw new RecipientLinkInvalid();
        }
    }

    public function confirm(\DateTimeImmutable $now): void
    {
        $this->close(RecipientInvitationStatus::Confirmed, $now);
    }

    public function decline(\DateTimeImmutable $now): void
    {
        $this->close(RecipientInvitationStatus::Declined, $now);
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $this->close(RecipientInvitationStatus::Cancelled, $now);
    }

    public function isPendingAt(\DateTimeImmutable $now): bool
    {
        return RecipientInvitationStatus::Pending === $this->status && $this->expiresAt > $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function projectId(): Uuid
    {
        return $this->projectId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function frequency(): Frequency
    {
        return $this->frequency;
    }

    public function invitedBy(): Uuid
    {
        return $this->invitedBy;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function status(): RecipientInvitationStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function closedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    private function close(RecipientInvitationStatus $status, \DateTimeImmutable $now): void
    {
        if (RecipientInvitationStatus::Pending === $this->status) {
            $this->status = $status;
            $this->closedAt = $now;
        }
    }
}
