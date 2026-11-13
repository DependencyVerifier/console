<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Audit;

use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Model\RecipientInvitation;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Model\WebhookChannel;
use Notification\Infrastructure\Doctrine\NotificationManager;
use Shared\Application\Audit\AuditScopeResolver;
use Shared\Application\Audit\Scope;
use Shared\Application\Audit\Target;
use Symfony\Component\Uid\Uuid;

/**
 * Team of a subscription, an invitation of a recipient, a webhook or a rule, read through the ORM identity map.
 */
final readonly class NotificationScopeResolver implements AuditScopeResolver
{
    private const array TYPES = [
        'notification_subscription' => Subscription::class,
        'notification_recipient_invitation' => RecipientInvitation::class,
        'notification_webhook' => WebhookChannel::class,
        'notification_rule' => NotificationRule::class,
    ];

    public function __construct(private NotificationManager $manager)
    {
    }

    public function supports(Target $target): bool
    {
        return isset(self::TYPES[$target->type]) && Uuid::isValid($target->id());
    }

    public function resolve(Target $target): Scope
    {
        $object = $this->manager->get()->find(self::TYPES[$target->type], Uuid::fromString($target->id()));

        return null === $object ? Scope::none() : Scope::team($object->teamId()->toRfc4122());
    }
}
