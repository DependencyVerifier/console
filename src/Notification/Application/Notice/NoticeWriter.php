<?php

declare(strict_types=1);

namespace Notification\Application\Notice;

use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\DeliveryChannel;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\Notice;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Repository\DeliveryRepository;
use Notification\Domain\Repository\NoticeRepository;
use Notification\Domain\Repository\NotificationRuleRepository;
use Notification\Domain\Repository\SubscriptionRepository;
use Notification\Domain\Repository\WebhookChannelRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\FilterRecipients;
use Symfony\Component\Uid\Uuid;

/**
 * An occurrence to the outbox: one notice per rule retaining it (never twice), one delivery per webhook of the rule
 * still in the team, and per subscriber of its scope who is active, still sees it, and did not turn it off.
 */
final readonly class NoticeWriter
{
    public function __construct(
        private NotificationRuleRepository $rules,
        private WebhookChannelRepository $webhooks,
        private SubscriptionRepository $subscriptions,
        private NoticeRepository $notices,
        private DeliveryRepository $deliveries,
        private QueryBus $queries,
        private ClockInterface $clock,
    ) {
    }

    public function write(Occurrence $occurrence): void
    {
        $team = Uuid::fromString($occurrence->teamId);
        $project = null === $occurrence->subject->projectId ? null : Uuid::fromString($occurrence->subject->projectId);
        $now = $this->clock->now();
        $subscribers = null;
        foreach ($this->rules->ofScope($team, $project) as $rule) {
            if (!$rule->retains($occurrence->subject) || $this->notices->has($rule->id(), $occurrence->dedupeKey)) {
                continue;
            }

            $notice = Notice::of(Uuid::v7(), $rule, $occurrence->subject->event, $occurrence->dedupeKey, $occurrence->content, $now);
            $this->notices->add($notice);
            foreach ($this->webhooksOf($rule) as $webhook) {
                $this->deliveries->add(Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Webhook, $webhook, Frequency::Immediate, $now));
            }

            if ($rule->emailsSubscribers()) {
                $subscribers ??= $this->subscribers($team, $project);
                foreach ($subscribers as $userId => $frequency) {
                    $this->deliveries->add(Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Email, $userId, $frequency, $now));
                }
            }
        }
    }

    /**
     * @return array<string>
     */
    private function webhooksOf(NotificationRule $rule): array
    {
        $team = array_map(static fn ($webhook): string => $webhook->id()->toRfc4122(), $this->webhooks->ofTeam($rule->teamId()));

        return array_intersect($rule->webhookIds(), $team);
    }

    /**
     * @return array<string, Frequency> by account
     */
    private function subscribers(Uuid $team, ?Uuid $project): array
    {
        $frequencies = [];
        foreach ($this->subscriptions->ofScope($team, $project) as $subscription) {
            if (Frequency::Off !== $subscription->frequency()) {
                $frequencies[$subscription->userId()->toRfc4122()] = $subscription->frequency();
            }
        }

        if ([] === $frequencies) {
            return [];
        }

        $seeing = $this->queries->ask(new FilterRecipients($team->toRfc4122(), $project?->toRfc4122(), array_keys($frequencies)));
        $accounts = $this->queries->ask(new FindAccounts($seeing));
        $kept = [];
        foreach ($seeing as $userId) {
            if (isset($accounts[$userId]) && $accounts[$userId]->active) {
                $kept[$userId] = $frequencies[$userId];
            }
        }

        return $kept;
    }
}
