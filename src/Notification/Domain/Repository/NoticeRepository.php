<?php

declare(strict_types=1);

namespace Notification\Domain\Repository;

use Notification\Domain\Model\Notice;
use Symfony\Component\Uid\Uuid;

interface NoticeRepository
{
    public function find(Uuid $id): ?Notice;

    public function has(Uuid $ruleId, string $dedupeKey): bool;

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, Notice> by id
     */
    public function findMany(array $ids): array;

    public function add(Notice $notice): void;

    public function deleteTeam(Uuid $teamId): void;
}
