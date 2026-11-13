<?php

declare(strict_types=1);

namespace Notification\Application\Query\Handler;

use Notification\Application\Query\ListWebhooks;
use Notification\Application\Query\View\Pages;
use Notification\Application\Query\View\WebhookRow;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\WebhookChannel;
use Notification\Domain\Repository\WebhookChannelRepository;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;
use Symfony\Component\Uid\Uuid;

final readonly class ListWebhooksHandler implements QueryHandler
{
    public function __construct(private NotificationAccess $access, private WebhookChannelRepository $webhooks)
    {
    }

    /**
     * @return Page<WebhookRow>
     */
    public function __invoke(ListWebhooks $query): Page
    {
        $this->access->assertChannels($query->teamId);
        $rows = array_map(static fn (WebhookChannel $w): WebhookRow => new WebhookRow($w->id()->toRfc4122(), $w->name(), $w->url(), $w->createdAt(), $w->lastCalledAt(), $w->lastSucceeded(), $w->lastError()), $this->webhooks->ofTeam(Uuid::fromString($query->teamId)));

        return Pages::of($rows, $query->page, static fn (WebhookRow $row): string => $row->name.' '.$row->url);
    }
}
