<?php

declare(strict_types=1);

namespace Notification\Application\Command\Send\Handler;

use Notification\Application\Command\Send\Unsubscribe;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Repository\SubscriptionRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

final readonly class UnsubscribeHandler implements CommandHandler
{
    public function __construct(private SubscriptionRepository $subscriptions)
    {
    }

    public function __invoke(Unsubscribe $command): void
    {
        $subscription = (Uuid::isValid($command->subscriptionId) ? $this->subscriptions->find(Uuid::fromString($command->subscriptionId)) : null) ?? throw ResourceNotFound::of('subscription');
        $subscription->change(Frequency::Off);
    }
}
