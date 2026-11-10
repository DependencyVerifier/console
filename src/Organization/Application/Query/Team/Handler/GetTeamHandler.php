<?php

declare(strict_types=1);

namespace Organization\Application\Query\Team\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\Team\GetTeam;
use Organization\Application\Query\View\QuotaUsage;
use Organization\Application\Query\View\TeamView;
use Organization\Application\Security\ActorPermissions;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\TeamRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Domain\Authorization\Permission;

/**
 * A team for its members (404 for anyone else), with its quota gauges (only the three quotas enforced in this step
 * carry a usage) and the gestures the actor may do.
 */
final readonly class GetTeamHandler implements QueryHandler
{
    private const array GESTURES = [
        Permission::TeamEdit, Permission::TeamMembersManage, Permission::TeamAuditView, Permission::ProjectCreate,
        Permission::TeamQuotasManage,
    ];

    public function __construct(
        private ActorPermissions $permissions,
        private TeamRepository $teams,
        private OrganizationReadModel $read,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetTeam $query): TeamView
    {
        $permissions = $this->permissions->current();
        $team = $this->teams->find(TeamId::fromString($query->teamId));
        if (null === $team || !$permissions->isTeamMember($query->teamId)) {
            throw ResourceNotFound::of('team');
        }

        $projects = $this->read->projectCount($query->teamId);
        $members = $this->read->activeMembers($query->teamId);
        $invitations = $this->read->pendingInvitations($query->teamId, $this->clock->now());
        $usage = [
            QuotaName::MaxProjects->value => $projects,
            QuotaName::MaxMembers->value => $members + $invitations,
        ];
        $quotas = [];
        foreach (QuotaName::cases() as $name) {
            $quotas[] = new QuotaUsage($name->value, $team->quotas()->get($name)->toStorage(), $usage[$name->value] ?? 0, isset($usage[$name->value]));
        }

        $can = [];
        foreach (self::GESTURES as $gesture) {
            $can[$gesture->value] = $permissions->allows($gesture, $query->teamId);
        }

        return new TeamView($query->teamId, $team->name(), $team->slug(), $permissions->teamRole($query->teamId)?->value, $team->isAdminEstablished(), $quotas, $can, $members, $projects, $invitations, $team->createdAt());
    }
}
