<?php

declare(strict_types=1);

namespace Notification\Application\Security;

use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\GetTeamAccess;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\TeamAccess;
use Shared\Domain\Authorization\Permission;

/**
 * Who sees and manages the notifications (cadrage §7bis): a project's are seen by whoever sees the project, managed
 * with `project.notifications.manage`, subscribed to with `project.notifications.subscribe`; the fleet's (no project)
 * are seen by whoever sees the fleet (every team role but TEAM_GUEST), managed with `team.channels.manage` like the
 * webhooks, subscribed to by a member who sees the fleet. Out of sight is 404 (Organization decides for the team),
 * visible without the permission 403.
 */
final readonly class NotificationAccess
{
    public function __construct(private QueryBus $queries, private PermissionChecker $permissions)
    {
    }

    /**
     * @throws ResourceNotFound
     * @throws PermissionDenied
     */
    public function assertSees(string $teamId, ?string $projectId): TeamAccess
    {
        $team = $this->queries->ask(new GetTeamAccess($teamId));
        if (null === $projectId) {
            if (!$team->seesFleet()) {
                throw PermissionDenied::for(Permission::HostView->value);
            }
        } elseif (!$this->queries->ask(new GetVisibleProjects($teamId))->sees($projectId)) {
            throw ResourceNotFound::of('project');
        }

        return $team;
    }

    /**
     * @throws ResourceNotFound
     * @throws PermissionDenied
     */
    public function assertManages(string $teamId, ?string $projectId): void
    {
        $this->assertSees($teamId, $projectId);
        if (!$this->manages($teamId, $projectId)) {
            throw PermissionDenied::for((null === $projectId ? Permission::TeamChannelsManage : Permission::ProjectNotificationsManage)->value);
        }
    }

    /**
     * @throws ResourceNotFound
     * @throws PermissionDenied
     */
    public function assertSubscribes(string $teamId, ?string $projectId): void
    {
        $team = $this->assertSees($teamId, $projectId);
        if (!$this->subscribes($team, $projectId)) {
            throw PermissionDenied::for(Permission::ProjectNotificationsSubscribe->value);
        }
    }

    /**
     * The webhooks of a team are the team's channels: `team.channels.manage`.
     *
     * @throws ResourceNotFound
     * @throws PermissionDenied
     */
    public function assertChannels(string $teamId): void
    {
        $this->assertManages($teamId, null);
    }

    public function manages(string $teamId, ?string $projectId): bool
    {
        return null === $projectId
            ? $this->permissions->isGranted(Permission::TeamChannelsManage, $teamId)
            : $this->permissions->isGranted(Permission::ProjectNotificationsManage, $teamId, $projectId);
    }

    /**
     * A member only (a super admin outside the team receives nothing: memberships decide what a notification tells).
     */
    public function subscribes(TeamAccess $team, ?string $projectId): bool
    {
        return null !== $team->role && (null === $projectId ? $team->seesFleet() : $this->permissions->isGranted(Permission::ProjectNotificationsSubscribe, $team->teamId, $projectId));
    }
}
