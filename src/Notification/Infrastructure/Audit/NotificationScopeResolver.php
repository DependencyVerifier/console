<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Audit;

use Notification\Domain\Model\Subscription;
use Notification\Infrastructure\Doctrine\NotificationManager;
use Shared\Application\Audit\AuditScopeResolver;
use Shared\Application\Audit\Scope;
use Shared\Application\Audit\Target;
use Symfony\Component\Uid\Uuid;

/**
 * Team of a subscription, read through the ORM identity map.
 */
final readonly class NotificationScopeResolver implements AuditScopeResolver
{
    public function __construct(private NotificationManager $manager)
    {
    }

    public function supports(Target $target): bool
    {
        return 'notification_subscription' === $target->type && Uuid::isValid($target->id());
    }

    public function resolve(Target $target): Scope
    {
        $subscription = $this->manager->get()->find(Subscription::class, Uuid::fromString($target->id()));

        return null === $subscription ? Scope::none() : Scope::team($subscription->teamId()->toRfc4122());
    }
}
