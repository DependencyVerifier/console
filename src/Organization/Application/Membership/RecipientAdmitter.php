<?php

declare(strict_types=1);

namespace Organization\Application\Membership;

use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\PermissionGrant;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use Organization\Domain\Repository\PermissionGrantRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\RecipientAdmission;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class RecipientAdmitter implements RecipientAdmission
{
    public function __construct(private OrganizationAccess $access, private PermissionGrantRepository $grants, private ClockInterface $clock)
    {
    }

    public function admitRecipient(string $teamId, string $projectId, string $userId): void
    {
        $team = $this->access->lockTeam(TeamId::fromString($teamId));
        $id = ProjectId::fromString($projectId);
        $project = $this->access->project($id);
        if (!$project->teamId()->equals($team->id())) {
            throw ResourceNotFound::of('project');
        }

        $this->access->assert(Permission::ProjectNotificationsManage, $team->id(), $id);
        $now = $this->clock->now();
        $user = Uuid::fromString($userId);
        $by = $this->access->actorId();
        if (!$team->isMember($user)) {
            $team->admit($user, TeamRole::Guest, $now);
            $this->grants->add(PermissionGrant::record($team->id(), null, $user, GrantChange::RoleAssigned, TeamRole::Guest->value, $by, $now));
        }

        if (!$project->isMember($user)) {
            $project->admit($user, ProjectRole::Viewer, $now);
            $this->grants->add(PermissionGrant::record($team->id(), $id, $user, GrantChange::RoleAssigned, ProjectRole::Viewer->value, $by, $now));
        }
    }
}
