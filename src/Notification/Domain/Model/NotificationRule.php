<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * Who hears of what: the events of a project (an environment of it, or all), or of the fleet of the team (no project:
 * hosts, applications not attached, agents); thresholds for the vulnerabilities; the webhooks to call (at once), and
 * whether the subscribers are emailed (each at the frequency of its subscription). A project may have no rule at all.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_rule')]
#[ORM\Index(name: 'notification_rule_team', columns: ['team_id'])]
class NotificationRule
{
    public const array SEVERITIES = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
    public const int MAX_NAME = 100;

    /**
     * @param list<string> $events
     * @param list<string> $webhookIds
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $projectId,
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $environmentId,
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column(type: 'json')]
        private array $events,
        #[ORM\Column(length: 16, nullable: true)]
        private ?string $minSeverity,
        #[ORM\Column(nullable: true)]
        private ?float $minCvss,
        #[ORM\Column]
        private bool $knownExploitedOnly,
        #[ORM\Column(nullable: true)]
        private ?float $minEpss,
        #[ORM\Column]
        private int $silentHours,
        #[ORM\Column(type: 'json')]
        private array $webhookIds,
        #[ORM\Column]
        private bool $emailSubscribers,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<NotificationEvent> $events
     * @param list<Uuid>              $webhookIds
     */
    public static function define(
        Uuid $id,
        Uuid $teamId,
        ?Uuid $projectId,
        ?Uuid $environmentId,
        string $name,
        array $events,
        RuleThresholds $thresholds,
        array $webhookIds,
        bool $emailSubscribers,
        \DateTimeImmutable $now,
    ): self {
        $rule = new self($id, $teamId, $projectId, $environmentId, '', [], null, null, false, null, 24, [], false, $now);
        $rule->redefine($environmentId, $name, $events, $thresholds, $webhookIds, $emailSubscribers);

        return $rule;
    }

    /**
     * Everything but the team and the project (a rule never moves).
     *
     * @param list<NotificationEvent> $events
     * @param list<Uuid>              $webhookIds
     */
    public function redefine(?Uuid $environmentId, string $name, array $events, RuleThresholds $thresholds, array $webhookIds, bool $emailSubscribers): void
    {
        $name = u($name)->trim();
        if ($name->isEmpty() || $name->length() > self::MAX_NAME) {
            throw new InvalidNotificationSetting('A name of 1 to 100 characters.');
        }

        if (null !== $environmentId && null === $this->projectId) {
            throw new InvalidNotificationSetting('An environment belongs to a project.');
        }

        if ([] === $webhookIds && !$emailSubscribers) {
            throw new InvalidNotificationSetting('Somebody to tell: a webhook or the subscribers.');
        }

        // The agents are the fleet's: a project rule cannot tell of them.
        $kept = null === $this->projectId ? $events : array_filter($events, static fn (NotificationEvent $e): bool => !$e->isFleetOnly());
        $values = array_values(array_unique(array_map(static fn (NotificationEvent $e): string => $e->value, $kept)));
        if ([] === $values) {
            throw new InvalidNotificationSetting('At least one event of this scope.');
        }

        $this->environmentId = $environmentId;
        $this->name = $name->toString();
        $this->events = $values;
        $this->minSeverity = $thresholds->minSeverity;
        $this->minCvss = $thresholds->minCvss;
        $this->knownExploitedOnly = $thresholds->knownExploitedOnly;
        $this->minEpss = $thresholds->minEpss;
        $this->silentHours = $thresholds->silentHours;
        $this->webhookIds = array_values(array_unique(array_map(static fn (Uuid $w): string => $w->toRfc4122(), $webhookIds)));
        $this->emailSubscribers = $emailSubscribers;
    }

    /**
     * A webhook deleted: no longer called. The rule stays, even with nobody to tell (shown so on its page).
     */
    public function forgetWebhook(Uuid $webhookId): void
    {
        $this->webhookIds = array_values(array_diff($this->webhookIds, [$webhookId->toRfc4122()]));
    }

    /**
     * Whether this rule tells of the event.
     */
    public function retains(NotificationSubject $subject): bool
    {
        if (!\in_array($subject->event->value, $this->events, true) || $subject->projectId !== $this->projectId?->toRfc4122()) {
            return false;
        }

        if (null !== $this->environmentId && $subject->environmentId !== $this->environmentId->toRfc4122()) {
            return false;
        }

        if (NotificationEvent::AgentSilent === $subject->event) {
            return $subject->silentHours >= $this->silentHours;
        }

        if (!\in_array($subject->event, [NotificationEvent::VulnerabilityOpened, NotificationEvent::VulnerabilityFixed], true)) {
            return true;
        }

        return (null === $this->minSeverity || \in_array($subject->severity, array_keys(array_filter(self::SEVERITIES, fn (int $rank): bool => $rank >= self::SEVERITIES[$this->minSeverity])), true))
            && (null === $this->minCvss || ($subject->cvss ?? 0.0) >= $this->minCvss)
            && (!$this->knownExploitedOnly || $subject->knownExploited)
            && (null === $this->minEpss || ($subject->epss ?? 0.0) >= $this->minEpss);
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

    public function environmentId(): ?Uuid
    {
        return $this->environmentId;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<NotificationEvent>
     */
    public function events(): array
    {
        return array_map(NotificationEvent::from(...), $this->events);
    }

    public function thresholds(): RuleThresholds
    {
        return new RuleThresholds($this->minSeverity, $this->minCvss, $this->knownExploitedOnly, $this->minEpss, $this->silentHours);
    }

    /**
     * @return list<string>
     */
    public function webhookIds(): array
    {
        return $this->webhookIds;
    }

    public function emailsSubscribers(): bool
    {
        return $this->emailSubscribers;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
