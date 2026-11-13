<?php

declare(strict_types=1);

namespace Notification\Application\Command\Webhook\Handler;

use Notification\Application\Command\Webhook\TestWebhook;
use Notification\Application\Port\WebhookCaller;
use Notification\Application\Webhook\WebhookRequest;
use Notification\Domain\Model\WebhookSecretBinding;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\SecretBox;
use Symfony\Component\Uid\Uuid;

final readonly class TestWebhookHandler implements CommandHandler
{
    public function __construct(private WebhookOfTeam $webhooks, private SecretBox $secrets, private WebhookCaller $caller, private ClockInterface $clock)
    {
    }

    public function __invoke(TestWebhook $command): void
    {
        $webhook = $this->webhooks->get($command->webhookId);
        $now = $this->clock->now();
        $secret = $this->secrets->decrypt($webhook->secret(), WebhookSecretBinding::for($webhook->id()));
        $webhook->called($now, $this->caller->call(WebhookRequest::test($webhook->url(), $secret, $webhook->teamId()->toRfc4122(), Uuid::v7()->toRfc4122(), $now)));
    }
}
