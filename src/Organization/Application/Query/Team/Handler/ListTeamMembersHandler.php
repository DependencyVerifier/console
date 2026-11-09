<?php

declare(strict_types=1);

namespace Organization\Application\Query\Team\Handler;

use Organization\Application\Query\MemberEmails;
use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\Team\ListTeamMembers;
use Organization\Application\Query\View\MemberRow;
use Organization\Application\Security\ActorPermissions;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

/**
 * Members of a team: visible to every team role but TEAM_GUEST (who only sees their projects).
 */
final readonly class ListTeamMembersHandler implements QueryHandler
{
    public function __construct(private ActorPermissions $permissions, private OrganizationReadModel $read, private MemberEmails $emails)
    {
    }

    /**
     * @return Page<MemberRow>
     */
    public function __invoke(ListTeamMembers $query): Page
    {
        $permissions = $this->permissions->current();
        if (!$permissions->isTeamMember($query->teamId)) {
            throw ResourceNotFound::of('team');
        }

        if (!$permissions->seesAllProjectsOf($query->teamId)) {
            throw PermissionDenied::for('team.members.view');
        }

        return $this->emails->fill($this->read->teamMembers($query->teamId, $query->page));
    }
}
