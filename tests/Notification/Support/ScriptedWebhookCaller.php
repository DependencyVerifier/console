<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Notification\Application\Port\WebhookCaller;
use Notification\Application\Webhook\WebhookRequest;

final class ScriptedWebhookCaller implements WebhookCaller
{
    /** @var list<WebhookRequest> */
    public array $requests = [];
    public ?string $failure = null;

    public function call(WebhookRequest $request): ?string
    {
        $this->requests[] = $request;

        return $this->failure;
    }
}
