<?php

declare(strict_types=1);

namespace Notification\Application\Query\Handler;

use Notification\Application\Query\GetNotifications;
use Notification\Application\Query\View\NotificationsView;
use Notification\Application\Query\View\Option;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Repository\SubscriptionRepository;
use Notification\Domain\Repository\WebhookChannelRepository;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Security\CurrentActor;
use Symfony\Component\Uid\Uuid;

final readonly class GetNotificationsHandler implements QueryHandler
{
    public function __construct(
        private NotificationAccess $access,
        private SubscriptionRepository $subscriptions,
        private WebhookChannelRepository $webhooks,
        private QueryBus $queries,
        private CurrentActor $actor,
    ) {
    }

    public function __invoke(GetNotifications $query): NotificationsView
    {
        $team = $this->access->assertSees($query->teamId, $query->projectId);
        $manages = $this->access->manages($query->teamId, $query->projectId);
        $actor = $this->actor->actor();
        $mine = $actor->isUser() ? $this->subscriptions->of($actor->userId(), Uuid::fromString($query->teamId), null === $query->projectId ? null : Uuid::fromString($query->projectId)) : null;
        $events = array_values(array_filter(NotificationEvent::cases(), static fn (NotificationEvent $e): bool => null === $query->projectId || !$e->isFleetOnly()));

        $environments = [];
        if ($manages && null !== $query->projectId) {
            foreach ($this->queries->ask(new ListTeamEnvironments($query->teamId)) as $environment) {
                if ($environment->projectId === $query->projectId) {
                    $environments[] = new Option($environment->environmentId, $environment->name);
                }
            }
        }

        return new NotificationsView(
            $query->teamId,
            $query->projectId,
            $manages,
            $this->access->manages($query->teamId, null),
            $this->access->subscribes($team, $query->projectId),
            $mine?->frequency()->value,
            array_map(static fn (NotificationEvent $e): string => $e->value, $events),
            $manages ? array_map(static fn ($w): Option => new Option($w->id()->toRfc4122(), $w->name()), $this->webhooks->ofTeam(Uuid::fromString($query->teamId))) : [],
            $environments,
        );
    }
}
