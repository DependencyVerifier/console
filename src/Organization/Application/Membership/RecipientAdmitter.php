<?php

declare(strict_types=1);

namespace Organization\Application\Membership;

use Organization\Application\Security\MemberPermissions;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\PermissionGrant;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use Organization\Domain\Repository\PermissionGrantRepository;
use Organization\Domain\Repository\ProjectRepository;
use Organization\Domain\Repository\TeamRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\RecipientAdmission;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class RecipientAdmitter implements RecipientAdmission
{
    public function __construct(
        private TeamRepository $teams,
        private ProjectRepository $projects,
        private MemberPermissions $members,
        private PermissionGrantRepository $grants,
        private ClockInterface $clock,
    ) {
    }

    public function admitRecipient(string $teamId, string $projectId, string $userId, string $grantedBy): void
    {
        $team = $this->teams->lockById(TeamId::fromString($teamId)) ?? throw ResourceNotFound::of('team');
        $id = ProjectId::fromString($projectId);
        $project = $this->projects->find($id);
        if (null === $project || !$project->teamId()->equals($team->id())) {
            throw ResourceNotFound::of('project');
        }

        if (!$this->members->of($grantedBy)->allows(Permission::ProjectNotificationsManage, $teamId, $projectId)) {
            throw PermissionDenied::for(Permission::ProjectNotificationsManage->value);
        }

        $now = $this->clock->now();
        $user = Uuid::fromString($userId);
        $by = Uuid::fromString($grantedBy);
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
