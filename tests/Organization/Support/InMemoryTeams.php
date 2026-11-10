<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\TeamRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryTeams implements TeamRepository
{
    /** @var array<string, Team> */
    public array $teams = [];

    /** @var list<string> */
    public array $locked = [];

    /** @var list<string> ids listed by idsOfMember but gone when locked (deleted meanwhile) */
    public array $vanished = [];

    public function __construct(Team ...$teams)
    {
        foreach ($teams as $team) {
            $this->teams[$team->id()->toString()] = $team;
        }
    }

    public function find(TeamId $id): ?Team
    {
        return $this->teams[$id->toString()] ?? null;
    }

    public function lockById(TeamId $id): ?Team
    {
        $this->locked[] = $id->toString();

        return \in_array($id->toString(), $this->vanished, true) ? null : $this->find($id);
    }

    public function add(Team $team): void
    {
        $this->teams[$team->id()->toString()] = $team;
    }

    public function slugExists(string $slug): bool
    {
        return array_any($this->teams, static fn (Team $team): bool => $team->slug() === $slug);
    }

    public function idsOfMember(Uuid $userId): array
    {
        $ids = array_values(array_map(static fn (Team $team): TeamId => $team->id(), array_filter($this->teams, static fn (Team $team): bool => $team->isMember($userId))));
        usort($ids, static fn (TeamId $a, TeamId $b): int => $a->toString() <=> $b->toString());

        return $ids;
    }

    public function remove(Team $team): void
    {
        unset($this->teams[$team->id()->toString()]);
    }
}
