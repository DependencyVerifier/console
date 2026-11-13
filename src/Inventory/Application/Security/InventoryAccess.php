<?php

declare(strict_types=1);

namespace Inventory\Application\Security;

use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Domain\Authorization\Permission;

/**
 * The scope of the actor on a team's inventory (404 outside the team, Organization decides), and the gestures it may
 * do on a project.
 */
final readonly class InventoryAccess
{
    public function __construct(private QueryBus $queries, private PermissionChecker $permissions)
    {
    }

    public function scope(string $teamId): InventoryScope
    {
        $projects = $this->queries->ask(new GetVisibleProjects($teamId));
        $environments = [];
        foreach ($this->queries->ask(new ListTeamEnvironments($teamId)) as $environment) {
            $environments[$environment->environmentId] = $environment;
        }

        $visible = null;
        if (!$projects->all) {
            $visible = [];
            foreach ($environments as $environment) {
                if ($projects->sees($environment->projectId)) {
                    $visible[] = $environment->environmentId;
                }
            }
        }

        return new InventoryScope($teamId, $environments, $visible, $projects->all ? null : $projects->projectIds);
    }

    public function mayAssignIn(string $teamId, string $projectId): bool
    {
        return $this->permissions->isGranted(Permission::ProjectAppsAssign, $teamId, $projectId);
    }

    /**
     * Who documents an application: project.apps.assign on its project, host.edit on the team while it is not attached.
     */
    public function mayDocument(string $teamId, ?string $projectId): bool
    {
        return null === $projectId ? $this->mayEditHosts($teamId) : $this->mayAssignIn($teamId, $projectId);
    }

    public function mayEditHosts(string $teamId): bool
    {
        return $this->permissions->isGranted(Permission::HostEdit, $teamId);
    }
}
