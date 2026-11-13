<?php

declare(strict_types=1);

namespace Notification\Application\Command\Webhook\Handler;

use Notification\Application\Command\Webhook\RegisterWebhook;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\WebhookChannel;
use Notification\Domain\Model\WebhookSecretBinding;
use Notification\Domain\Repository\WebhookChannelRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\SecretBox;
use Symfony\Component\Uid\Uuid;

final readonly class RegisterWebhookHandler implements CommandHandler
{
    public function __construct(private NotificationAccess $access, private WebhookChannelRepository $webhooks, private SecretBox $secrets, private ClockInterface $clock)
    {
    }

    public function __invoke(RegisterWebhook $command): void
    {
        $this->access->assertChannels($command->teamId);
        $id = Uuid::fromString($command->webhookId);
        $this->webhooks->add(WebhookChannel::register($id, Uuid::fromString($command->teamId), $command->name, $command->url, $this->secrets->encrypt($command->plainSecret, WebhookSecretBinding::for($id)), $this->clock->now()));
    }
}
