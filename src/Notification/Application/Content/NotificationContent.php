<?php

declare(strict_types=1);

namespace Notification\Application\Content;

use Notification\Domain\Repository\DeliveryRepository;
use Notification\Domain\Repository\NoticeRepository;
use Notification\Domain\Repository\NotificationRuleRepository;
use Notification\Domain\Repository\SubscriptionRepository;
use Notification\Domain\Repository\WebhookChannelRepository;
use Shared\Application\Content\ProjectContentProbe;
use Shared\Application\Content\TeamContentEraser;
use Symfony\Component\Uid\Uuid;

/**
 * A deleted team takes its webhooks, rules, subscriptions and outbox. A project (or an environment) with rules cannot
 * be deleted: they are removed first, on purpose.
 */
final readonly class NotificationContent implements TeamContentEraser, ProjectContentProbe
{
    public function __construct(
        private NotificationRuleRepository $rules,
        private WebhookChannelRepository $webhooks,
        private SubscriptionRepository $subscriptions,
        private NoticeRepository $notices,
        private DeliveryRepository $deliveries,
    ) {
    }

    public function eraseTeam(string $teamId): void
    {
        $team = Uuid::fromString($teamId);
        $this->deliveries->deleteTeam($team);
        $this->notices->deleteTeam($team);
        $this->subscriptions->deleteTeam($team);
        $this->rules->deleteTeam($team);
        $this->webhooks->deleteTeam($team);
    }

    public function projectHasContent(string $projectId): bool
    {
        return $this->rules->hasProject(Uuid::fromString($projectId));
    }

    public function environmentHasContent(string $environmentId): bool
    {
        return $this->rules->hasEnvironment(Uuid::fromString($environmentId));
    }
}
