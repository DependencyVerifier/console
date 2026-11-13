<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An account hears of the rules of a project (or of the fleet of a team: no project) by email, at its frequency. One
 * per account and scope; `off` keeps the choice (an unsubscribe link sets it). Taken by the account itself, or by a
 * recipient who confirmed the invitation of a manager (`addedBy`): a subscription is always consented to.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_subscription')]
#[ORM\UniqueConstraint(name: 'notification_subscription_scope', columns: ['user_id', 'team_id', 'scope_key'])]
#[ORM\Index(name: 'notification_subscription_lookup', columns: ['team_id', 'scope_key'])]
class Subscription
{
    public const string FLEET = 'fleet';

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $addedBy = null;

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
        return new self($id, $teamId, $projectId, self::scopeKey($projectId), $userId, $frequency, $now);
    }

    /**
     * A recipient who confirmed the invitation of a manager to the notifications of a project.
     */
    public static function attach(Uuid $id, Uuid $teamId, Uuid $projectId, Uuid $userId, Frequency $frequency, Uuid $addedBy, \DateTimeImmutable $now): self
    {
        $subscription = new self($id, $teamId, $projectId, self::scopeKey($projectId), $userId, $frequency, $now);
        $subscription->addedBy = $addedBy;

        return $subscription;
    }

    public function addedBy(): ?Uuid
    {
        return $this->addedBy;
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
