<?php

declare(strict_types=1);

namespace Notification\Application\Port;

use Notification\Application\Webhook\WebhookRequest;

/**
 * Posts a signed request to a webhook, never to a private network, without following a redirect. Never throws: a
 * failure is returned (the HTTP status or the kind of error, never the answer) and counted as an attempt.
 */
interface WebhookCaller
{
    /**
     * @return ?string the failure, null on a 2xx answer
     */
    public function call(WebhookRequest $request): ?string;
}
