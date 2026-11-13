<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Notification\Domain\Event\RecipientAttached;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * An account hears of the rules of a project (or of the fleet of a team: no project) by email, at its frequency. One
 * per account and scope; `off` keeps the choice (an unsubscribe link sets it). Taken by the account itself, it is
 * confirmed at once; a recipient attached by a manager (`addedBy`) hears nothing until it confirms its address through
 * the link it receives (7 days, hashed single-use token).
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_subscription')]
#[ORM\UniqueConstraint(name: 'notification_subscription_scope', columns: ['user_id', 'team_id', 'scope_key'])]
#[ORM\Index(name: 'notification_subscription_lookup', columns: ['team_id', 'scope_key'])]
#[ORM\UniqueConstraint(name: 'notification_subscription_confirmation', columns: ['confirmation_hash'])]
class Subscription implements RecordsEvents
{
    use EventRecording;

    public const string FLEET = 'fleet';
    public const int CONFIRMATION_DAYS = 7;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $addedBy = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $confirmationHash = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmationExpiresAt = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $projectId,
        #[ORM\Column(length: 36)]
        private string $scopeKey,
        #[ORM\Column(type: 'uuid')]
        private Uuid $userId,
        #[ORM\Column(length: 16, enumType: Frequency::class)]
        private Frequency $frequency,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function subscribe(Uuid $id, Uuid $teamId, ?Uuid $projectId, Uuid $userId, Frequency $frequency, \DateTimeImmutable $now): self
    {
        $subscription = new self($id, $teamId, $projectId, self::scopeKey($projectId), $userId, $frequency, $now);
        $subscription->confirmedAt = $now;

        return $subscription;
    }

    /**
     * A recipient attached to a project by a manager: silent until confirmed.
     */
    public static function attach(Uuid $id, Uuid $teamId, Uuid $projectId, Uuid $userId, Frequency $frequency, Uuid $addedBy, #[\SensitiveParameter] string $plainToken, string $tokenHash, \DateTimeImmutable $now): self
    {
        if (Frequency::Off === $frequency) {
            throw new InvalidNotificationSetting('A recipient hears at once or in a digest.');
        }

        $subscription = new self($id, $teamId, $projectId, self::scopeKey($projectId), $userId, $frequency, $now);
        $subscription->addedBy = $addedBy;
        $subscription->sendConfirmation($plainToken, $tokenHash, $now);

        return $subscription;
    }

    /**
     * Attached again while unconfirmed: a new link (the former one no longer works). A confirmed one stays as is.
     */
    public function sendConfirmation(#[\SensitiveParameter] string $plainToken, string $tokenHash, \DateTimeImmutable $now): void
    {
        if ($this->isConfirmed()) {
            return;
        }

        $this->confirmationHash = $tokenHash;
        $this->confirmationExpiresAt = $now->modify(\sprintf('+%d days', self::CONFIRMATION_DAYS));
        $this->recordThat(new RecipientAttached($this->id->toRfc4122(), $this->teamId->toRfc4122(), $this->userId->toRfc4122(), $plainToken));
    }

    /**
     * Its link followed in time: it hears from now on; the link is spent.
     */
    public function confirm(\DateTimeImmutable $now): void
    {
        if (null === $this->confirmationExpiresAt || $this->confirmationExpiresAt <= $now) {
            throw new RecipientLinkInvalid();
        }

        $this->confirmedAt = $now;
        $this->confirmationHash = null;
        $this->confirmationExpiresAt = null;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->confirmedAt;
    }

    public function addedBy(): ?Uuid
    {
        return $this->addedBy;
    }

    public function confirmationHash(): ?string
    {
        return $this->confirmationHash;
    }

    public function confirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function confirmationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->confirmationExpiresAt;
    }

    public static function scopeKey(?Uuid $projectId): string
    {
        return $projectId?->toRfc4122() ?? self::FLEET;
    }

    public function change(Frequency $frequency): void
    {
        $this->frequency = $frequency;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function projectId(): ?Uuid
    {
        return $this->projectId;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function frequency(): Frequency
    {
        return $this->frequency;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
