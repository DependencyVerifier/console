<?php

declare(strict_types=1);

namespace Organization\Application\Query\Invitation\Handler;

use Organization\Application\Query\Invitation\ListAllInvitations;
use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\View\InvitationRow;
use Organization\Application\Security\ActorPermissions;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;
use Shared\Domain\Authorization\Permission;

/**
 * The instance's pending invitations, for whoever manages the accounts of the instance (the super admin).
 */
final readonly class ListAllInvitationsHandler implements QueryHandler
{
    public function __construct(private ActorPermissions $permissions, private OrganizationReadModel $read, private ClockInterface $clock)
    {
    }

    /**
     * @return Page<InvitationRow>
     */
    public function __invoke(ListAllInvitations $query): Page
    {
        if (!$this->permissions->current()->isSuperAdmin()) {
            throw PermissionDenied::for(Permission::InstanceUsersManage->value);
        }

        return $this->read->invitations(null, null, $query->page, $this->clock->now());
    }
}
