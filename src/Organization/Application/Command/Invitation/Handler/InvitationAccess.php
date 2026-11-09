<?php

declare(strict_types=1);

namespace Organization\Application\Command\Invitation\Handler;

use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Repository\InvitationRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Domain\Authorization\Permission;

/**
 * An invitation, checked on its real owner: its team locked, then `team.members.manage` (team invitation) or
 * `project.members.manage` (project invitation).
 */
final readonly class InvitationAccess
{
    public function __construct(private OrganizationAccess $access, private InvitationRepository $invitations)
    {
    }

    public function manage(string $invitationId): Invitation
    {
        $invitation = $this->invitations->find(InvitationId::fromString($invitationId)) ?? throw ResourceNotFound::of('invitation');
        $target = $invitation->target();
        $this->access->lockTeam($target->teamId());
        if ($target->isProject()) {
            $this->access->project($target->projectId());
            $this->access->assert(Permission::ProjectMembersManage, $target->teamId(), $target->projectId());
        } else {
            $this->access->assert(Permission::TeamMembersManage, $target->teamId());
        }

        return $invitation;
    }
}
