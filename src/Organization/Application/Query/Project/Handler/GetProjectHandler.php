<?php

declare(strict_types=1);

namespace Organization\Application\Query\Project\Handler;

use Organization\Application\Query\Project\GetProject;
use Organization\Application\Query\View\ProjectView;
use Organization\Application\Security\ActorPermissions;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Repository\InvitationRepository;
use Organization\Domain\Repository\ProjectRepository;
use Organization\Domain\Repository\TeamRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Domain\Authorization\Permission;

final readonly class GetProjectHandler implements QueryHandler
{
    private const array GESTURES = [
        Permission::ProjectEdit, Permission::ProjectDelete, Permission::ProjectMembersManage, Permission::ProjectEnvironmentsManage,
    ];

    public function __construct(
        private ActorPermissions $permissions,
        private ProjectRepository $projects,
        private TeamRepository $teams,
        private InvitationRepository $invitations,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetProject $query): ProjectView
    {
        $permissions = $this->permissions->current();
        $project = $this->projects->find(ProjectId::fromString($query->projectId));
        $team = null === $project ? null : $this->teams->find($project->teamId());
        if (null === $project || null === $team || !$permissions->seesProject($team->id()->toString(), $query->projectId)) {
            throw ResourceNotFound::of('project');
        }

        $can = [];
        foreach (self::GESTURES as $gesture) {
            $can[$gesture->value] = $permissions->allows($gesture, $team->id()->toString(), $query->projectId);
        }

        return new ProjectView(
            $query->projectId,
            $team->id()->toString(),
            $team->name(),
            $project->name(),
            $project->environmentCount(),
            $team->quotas()->get(QuotaName::MaxEnvironmentsPerProject)->toStorage(),
            $can,
            $project->slug(),
            \count($project->memberships()),
            $project->createdAt(),
            // A project invitation may add a TEAM_GUEST: it counts in the team's members (active + pending invitations).
            !$team->quotas()->get(QuotaName::MaxMembers)->allows($team->activeMemberCount() + $this->invitations->countPendingOfTeam($team->id(), $this->clock->now())),
        );
    }
}
