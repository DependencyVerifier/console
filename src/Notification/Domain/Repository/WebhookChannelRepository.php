<?php

declare(strict_types=1);

namespace Notification\Domain\Repository;

use Notification\Domain\Model\WebhookChannel;
use Symfony\Component\Uid\Uuid;

interface WebhookChannelRepository
{
    public function find(Uuid $id): ?WebhookChannel;

    /**
     * @return list<WebhookChannel> by name
     */
    public function ofTeam(Uuid $teamId): array;

    public function add(WebhookChannel $channel): void;

    public function remove(WebhookChannel $channel): void;

    public function deleteTeam(Uuid $teamId): void;
}
