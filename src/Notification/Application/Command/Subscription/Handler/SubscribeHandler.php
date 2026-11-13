<?php

declare(strict_types=1);

namespace Notification\Application\Command\Subscription\Handler;

use Notification\Application\Command\Subscription\Subscribe;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\InvalidNotificationSetting;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Repository\SubscriptionRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Security\CurrentActor;
use Symfony\Component\Uid\Uuid;

final readonly class SubscribeHandler implements CommandHandler
{
    public function __construct(private NotificationAccess $access, private SubscriptionRepository $subscriptions, private CurrentActor $actor, private ClockInterface $clock)
    {
    }

    public function __invoke(Subscribe $command): void
    {
        $this->access->assertSubscribes($command->teamId, $command->projectId);
        $frequency = Frequency::tryFrom($command->frequency) ?? throw new InvalidNotificationSetting('A frequency: immediate, daily, weekly or off.');
        $team = Uuid::fromString($command->teamId);
        $project = null === $command->projectId ? null : Uuid::fromString($command->projectId);
        $user = $this->actor->actor()->userId();

        $subscription = $this->subscriptions->of($user, $team, $project);
        if (null !== $subscription) {
            $subscription->change($frequency);
        } elseif (Frequency::Off !== $frequency) {
            $this->subscriptions->add(Subscription::subscribe(Uuid::v7(), $team, $project, $user, $frequency, $this->clock->now()));
        }
    }
}
