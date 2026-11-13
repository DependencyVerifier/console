<?php

declare(strict_types=1);

namespace Notification\Application\Query;

use Notification\Application\Query\View\WebhookRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The webhooks of the team, by name (`team.channels.manage`). Never their secret.
 *
 * @implements Query<Page<WebhookRow>>
 */
final readonly class ListWebhooks implements Query
{
    public function __construct(public string $teamId, public PageRequest $page)
    {
    }
}
