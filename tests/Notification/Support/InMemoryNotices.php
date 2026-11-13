<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Notification\Domain\Model\Notice;
use Notification\Domain\Repository\NoticeRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryNotices implements NoticeRepository
{
    /** @var list<Notice> */
    public array $notices = [];

    public function find(Uuid $id): ?Notice
    {
        return array_find($this->notices, static fn (Notice $n): bool => $n->id()->equals($id));
    }

    public function has(Uuid $ruleId, string $dedupeKey): bool
    {
        return array_any($this->notices, static fn (Notice $n): bool => $n->ruleId()->equals($ruleId) && $n->dedupeKey() === $dedupeKey);
    }

    public function findMany(array $ids): array
    {
        $notices = [];
        foreach ($ids as $id) {
            $notice = $this->find($id);
            if (null !== $notice) {
                $notices[$id->toRfc4122()] = $notice;
            }
        }

        return $notices;
    }

    public function add(Notice $notice): void
    {
        $this->notices[] = $notice;
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->notices = array_values(array_filter($this->notices, static fn (Notice $n): bool => !$n->teamId()->equals($teamId)));
    }
}
