<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Notification\Domain\Event\DeliveryScheduled;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * A notice told to one recipient (an account by email, a webhook): at most once per notice, channel and target. An
 * immediate one is attempted at once then again later (1, 5, 25 and 125 minutes), up to 5 attempts; a digest one
 * waits for its digest. The attempts are counted here, never by the bus: a failed attempt is not rolled back.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_delivery')]
#[ORM\UniqueConstraint(name: 'notification_delivery_once', columns: ['notice_id', 'channel', 'target'])]
#[ORM\Index(name: 'notification_delivery_due', columns: ['status', 'frequency', 'next_attempt_at'])]
class Delivery implements RecordsEvents
{
    use EventRecording;

    public const int MAX_ATTEMPTS = 5;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $noticeId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(length: 16, enumType: DeliveryChannel::class)]
        private DeliveryChannel $channel,
        #[ORM\Column(length: 36)]
        private string $target,
        #[ORM\Column(length: 16, enumType: Frequency::class)]
        private Frequency $frequency,
        #[ORM\Column(length: 16, enumType: DeliveryStatus::class)]
        private DeliveryStatus $status,
        #[ORM\Column]
        private int $attempts,
        #[ORM\Column]
        private \DateTimeImmutable $nextAttemptAt,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $doneAt,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $lastError,
    ) {
    }

    public static function schedule(Uuid $id, Notice $notice, DeliveryChannel $channel, string $target, Frequency $frequency, \DateTimeImmutable $now): self
    {
        if (Frequency::Off === $frequency || (DeliveryChannel::Webhook === $channel && Frequency::Immediate !== $frequency)) {
            throw new InvalidNotificationSetting('A delivery is immediate (always for a webhook) or in a digest.');
        }

        $delivery = new self($id, $notice->id(), $notice->teamId(), $channel, $target, $frequency, DeliveryStatus::Pending, 0, $now, null, null);
        if (Frequency::Immediate === $frequency) {
            $delivery->recordThat(new DeliveryScheduled($id->toRfc4122()));
        }

        return $delivery;
    }

    public function sent(\DateTimeImmutable $now): void
    {
        ++$this->attempts;
        $this->status = DeliveryStatus::Sent;
        $this->doneAt = $now;
        $this->lastError = null;
    }

    /**
     * An attempt failed: again later, or failed for good after the last one.
     */
    public function attemptFailed(string $error, \DateTimeImmutable $now): void
    {
        ++$this->attempts;
        $this->lastError = u($error)->truncate(255)->toString();
        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->status = DeliveryStatus::Failed;
            $this->doneAt = $now;

            return;
        }

        $this->nextAttemptAt = $now->modify(\sprintf('+%d minutes', 5 ** ($this->attempts - 1)));
    }

    public function drop(\DateTimeImmutable $now): void
    {
        $this->status = DeliveryStatus::Dropped;
        $this->doneAt = $now;
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return DeliveryStatus::Pending === $this->status && $this->nextAttemptAt <= $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function noticeId(): Uuid
    {
        return $this->noticeId;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function channel(): DeliveryChannel
    {
        return $this->channel;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function frequency(): Frequency
    {
        return $this->frequency;
    }

    public function status(): DeliveryStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function nextAttemptAt(): \DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function doneAt(): ?\DateTimeImmutable
    {
        return $this->doneAt;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
