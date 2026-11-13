<?php

declare(strict_types=1);

namespace Notification\Application\Command\Webhook\Handler;

use Notification\Application\Command\Webhook\DeleteWebhook;
use Notification\Domain\Repository\NotificationRuleRepository;
use Notification\Domain\Repository\WebhookChannelRepository;
use Shared\Application\Bus\CommandHandler;

final readonly class DeleteWebhookHandler implements CommandHandler
{
    public function __construct(private WebhookOfTeam $webhook, private WebhookChannelRepository $webhooks, private NotificationRuleRepository $rules)
    {
    }

    public function __invoke(DeleteWebhook $command): void
    {
        $webhook = $this->webhook->get($command->webhookId);
        foreach ($this->rules->callingWebhook($webhook->id()) as $rule) {
            $rule->forgetWebhook($webhook->id());
        }

        $this->webhooks->remove($webhook);
    }
}
