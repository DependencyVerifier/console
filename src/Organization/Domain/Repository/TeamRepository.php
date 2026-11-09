<?php

declare(strict_types=1);

namespace Organization\Domain\Repository;

use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamId;
use Symfony\Component\Uid\Uuid;

interface TeamRepository
{
    public function find(TeamId $id): ?Team;

    /**
     * SELECT … FOR UPDATE: the team is the consistency boundary (quotas, last admin, uniqueness).
     */
    public function lockById(TeamId $id): ?Team;

    public function add(Team $team): void;

    public function slugExists(string $slug): bool;

    /**
     * Teams where the account has a membership, in a stable order (team id) for multi-team locking.
     *
     * @return list<TeamId>
     */
    public function idsOfMember(Uuid $userId): array;
}
