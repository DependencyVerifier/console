<?php

declare(strict_types=1);

namespace Notification\Application\Command\Webhook\Handler;

use Notification\Application\Command\Webhook\EditWebhook;
use Shared\Application\Bus\CommandHandler;

final readonly class EditWebhookHandler implements CommandHandler
{
    public function __construct(private WebhookOfTeam $webhooks)
    {
    }

    public function __invoke(EditWebhook $command): void
    {
        $this->webhooks->get($command->webhookId)->edit($command->name, $command->url);
    }
}
