<?php

declare(strict_types=1);

namespace Organization\Application\Query\Team\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\Team\ListTeams;
use Organization\Application\Query\View\TeamSummary;
use Organization\Application\Security\ActorPermissions;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

final readonly class ListTeamsHandler implements QueryHandler
{
    public function __construct(private ActorPermissions $permissions, private OrganizationReadModel $read)
    {
    }

    /**
     * @return Page<TeamSummary>
     */
    public function __invoke(ListTeams $query): Page
    {
        $permissions = $this->permissions->current();
        $page = $this->read->teams($permissions->isSuperAdmin() ? null : $permissions->teamIds(), $query->page);

        return new Page(array_map(static fn (TeamSummary $team): TeamSummary => new TeamSummary(
            $team->id, $team->name, $team->slug, $permissions->teamRole($team->id)?->value, $team->members, $team->projects, $team->createdAt,
        ), $page->items), $page->filtered, $page->total);
    }
}
