<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient\Handler;

use Notification\Application\Command\Recipient\DetachRecipient;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Repository\SubscriptionRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Only a recipient attached by a manager, and the project it belongs to decides the permission.
 */
final readonly class DetachRecipientHandler implements CommandHandler
{
    public function __construct(private SubscriptionRepository $subscriptions, private NotificationAccess $access)
    {
    }

    public function __invoke(DetachRecipient $command): void
    {
        $subscription = Uuid::isValid($command->subscriptionId) ? $this->subscriptions->find(Uuid::fromString($command->subscriptionId)) : null;
        // Attached by a manager, so always to a project.
        $project = null === $subscription?->addedBy() ? null : $subscription->projectId();
        if (null === $subscription || null === $project) {
            throw ResourceNotFound::of('recipient');
        }

        $this->access->assertManages($subscription->teamId()->toRfc4122(), $project->toRfc4122());
        $this->subscriptions->remove($subscription);
    }
}
