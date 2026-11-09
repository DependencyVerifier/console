<?php

declare(strict_types=1);

namespace Organization\Application\Query\Team\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\Team\ListInvitations;
use Organization\Application\Query\View\InvitationRow;
use Organization\Application\Security\ActorPermissions;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;
use Shared\Domain\Authorization\Permission;

final readonly class ListInvitationsHandler implements QueryHandler
{
    public function __construct(private ActorPermissions $permissions, private OrganizationReadModel $read, private ClockInterface $clock)
    {
    }

    /**
     * @return Page<InvitationRow>
     */
    public function __invoke(ListInvitations $query): Page
    {
        $permissions = $this->permissions->current();
        if (!$permissions->isTeamMember($query->teamId)) {
            throw ResourceNotFound::of('team');
        }

        if (!$permissions->allows(Permission::TeamMembersManage, $query->teamId)) {
            throw PermissionDenied::for(Permission::TeamMembersManage->value);
        }

        return $this->read->invitations($query->teamId, $query->page, $this->clock->now());
    }
}
