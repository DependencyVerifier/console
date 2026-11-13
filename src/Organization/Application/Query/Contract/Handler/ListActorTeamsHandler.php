<?php

declare(strict_types=1);

namespace Organization\Application\Query\Contract\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Security\ActorPermissions;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\ActorTeam;
use Shared\Application\Contract\Organization\ListActorTeams;

use function Symfony\Component\String\u;

final readonly class ListActorTeamsHandler implements QueryHandler
{
    public function __construct(private ActorPermissions $permissions, private OrganizationReadModel $read)
    {
    }

    /**
     * @return list<ActorTeam>
     */
    public function __invoke(ListActorTeams $query): array
    {
        $permissions = $this->permissions->current();
        $ids = $permissions->teamIds();
        $teams = [];
        foreach ([] === $ids ? [] : $this->read->teamNames($ids) as $teamId => $name) {
            $role = $permissions->teamRole($teamId);
            if (null !== $role) {
                $teams[] = new ActorTeam($teamId, $name, $role->value);
            }
        }

        usort($teams, static fn (ActorTeam $a, ActorTeam $b): int => [u($a->name)->lower()->toString(), $a->teamId] <=> [u($b->name)->lower()->toString(), $b->teamId]);

        return $teams;
    }
}
