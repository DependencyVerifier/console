<?php

declare(strict_types=1);

namespace Organization\Application\Query\Project\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\Project\ListProjects;
use Organization\Application\Query\View\ProjectRow;
use Organization\Application\Security\ActorPermissions;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

final readonly class ListProjectsHandler implements QueryHandler
{
    public function __construct(private ActorPermissions $permissions, private OrganizationReadModel $read)
    {
    }

    /**
     * @return Page<ProjectRow>
     */
    public function __invoke(ListProjects $query): Page
    {
        $permissions = $this->permissions->current();
        if (!$permissions->isTeamMember($query->teamId)) {
            throw ResourceNotFound::of('team');
        }

        $visible = $permissions->seesAllProjectsOf($query->teamId) ? null : $permissions->projectIdsOf($query->teamId);

        return $this->read->projects($query->teamId, $visible, $query->page);
    }
}
