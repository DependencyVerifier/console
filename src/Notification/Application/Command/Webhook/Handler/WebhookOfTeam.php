<?php

declare(strict_types=1);

namespace Notification\Application\Command\Webhook\Handler;

use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\WebhookChannel;
use Notification\Domain\Repository\WebhookChannelRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Symfony\Component\Uid\Uuid;

/**
 * A webhook the actor manages: unknown is a 404, its real team decides the permission.
 */
final readonly class WebhookOfTeam
{
    public function __construct(private WebhookChannelRepository $webhooks, private NotificationAccess $access)
    {
    }

    public function get(string $webhookId): WebhookChannel
    {
        $webhook = (Uuid::isValid($webhookId) ? $this->webhooks->find(Uuid::fromString($webhookId)) : null) ?? throw ResourceNotFound::of('webhook');
        $this->access->assertChannels($webhook->teamId()->toRfc4122());

        return $webhook;
    }
}
