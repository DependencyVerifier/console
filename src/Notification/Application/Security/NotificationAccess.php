<?php

declare(strict_types=1);

namespace Notification\Application\Security;

use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Domain\Authorization\Permission;

/**
 * Who manages the notifications of a project (`project.notifications.manage`): a project out of sight is a 404, a
 * visible one without the permission a 403.
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
    public function assertManages(string $teamId, string $projectId): void
    {
        if (!$this->queries->ask(new GetVisibleProjects($teamId))->sees($projectId)) {
            throw ResourceNotFound::of('project');
        }

        if (!$this->permissions->isGranted(Permission::ProjectNotificationsManage, $teamId, $projectId)) {
            throw PermissionDenied::for(Permission::ProjectNotificationsManage->value);
        }
    }
}
