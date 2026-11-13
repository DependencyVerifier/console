<?php

declare(strict_types=1);

namespace Notification\Application\Command\Webhook\Handler;

use Notification\Application\Command\Webhook\RotateWebhookSecret;
use Notification\Domain\Model\WebhookSecretBinding;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\SecretBox;

final readonly class RotateWebhookSecretHandler implements CommandHandler
{
    public function __construct(private WebhookOfTeam $webhooks, private SecretBox $secrets)
    {
    }

    public function __invoke(RotateWebhookSecret $command): void
    {
        $webhook = $this->webhooks->get($command->webhookId);
        $webhook->rotateSecret($this->secrets->encrypt($command->plainSecret, WebhookSecretBinding::for($webhook->id())));
    }
}
