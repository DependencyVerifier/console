<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Notification\Domain\Model\WebhookChannel;
use Notification\Domain\Repository\WebhookChannelRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryWebhooks implements WebhookChannelRepository
{
    /** @var list<WebhookChannel> */
    public array $webhooks = [];

    public function find(Uuid $id): ?WebhookChannel
    {
        return array_find($this->webhooks, static fn (WebhookChannel $w): bool => $w->id()->equals($id));
    }

    public function ofTeam(Uuid $teamId): array
    {
        $webhooks = array_values(array_filter($this->webhooks, static fn (WebhookChannel $w): bool => $w->teamId()->equals($teamId)));
        usort($webhooks, static fn (WebhookChannel $a, WebhookChannel $b): int => $a->name() <=> $b->name());

        return $webhooks;
    }

    public function add(WebhookChannel $channel): void
    {
        $this->webhooks[] = $channel;
    }

    public function remove(WebhookChannel $channel): void
    {
        $this->webhooks = array_values(array_filter($this->webhooks, static fn (WebhookChannel $w): bool => $w !== $channel));
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->webhooks = array_values(array_filter($this->webhooks, static fn (WebhookChannel $w): bool => !$w->teamId()->equals($teamId)));
    }
}
