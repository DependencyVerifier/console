<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An event a rule retained, with what is told of it as it was then (the outbox): the deliveries read it, never the
 * other contexts. Unique per rule and occurrence of the event: an event handled twice is told once.
 *
 * @phpstan-type Content array<string, mixed>
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_notice')]
#[ORM\UniqueConstraint(name: 'notification_notice_occurrence', columns: ['rule_id', 'dedupe_key'])]
#[ORM\Index(name: 'notification_notice_team', columns: ['team_id'])]
class Notice
{
    /**
     * @param Content $content
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $ruleId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $projectId,
        #[ORM\Column(length: 32, enumType: NotificationEvent::class)]
        private NotificationEvent $event,
        #[ORM\Column(length: 255)]
        private string $dedupeKey,
        #[ORM\Column(type: 'json')]
        private array $content,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param Content $content
     */
    public static function of(Uuid $id, NotificationRule $rule, NotificationEvent $event, string $dedupeKey, array $content, \DateTimeImmutable $now): self
    {
        return new self($id, $rule->id(), $rule->teamId(), $rule->projectId(), $event, $dedupeKey, $content, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function ruleId(): Uuid
    {
        return $this->ruleId;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function projectId(): ?Uuid
    {
        return $this->projectId;
    }

    public function event(): NotificationEvent
    {
        return $this->event;
    }

    public function dedupeKey(): string
    {
        return $this->dedupeKey;
    }

    /**
     * @return Content
     */
    public function content(): array
    {
        return $this->content;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
