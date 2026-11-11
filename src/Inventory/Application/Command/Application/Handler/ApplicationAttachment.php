<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Application\Handler;

use Inventory\Application\Security\InventoryAccess;
use Inventory\Application\Security\InventoryScope;
use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Repository\AppInstanceRepository;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * The application the actor sees (404 otherwise), and the check of project.apps.assign on a project (403).
 */
final readonly class ApplicationAttachment
{
    public function __construct(private InventoryAccess $access, private AppInstanceRepository $apps)
    {
    }

    /**
     * @return array{AppInstance, InventoryScope}
     */
    public function visible(string $appId): array
    {
        $app = (Uuid::isValid($appId) ? $this->apps->find(Uuid::fromString($appId)) : null) ?? throw ResourceNotFound::of('application');
        $scope = $this->access->scope($app->teamId()->toRfc4122());
        if (!$scope->seesEnvironment($app->environmentId()?->toRfc4122())) {
            throw ResourceNotFound::of('application');
        }

        return [$app, $scope];
    }

    public function assertMayAssign(AppInstance $app, string $projectId): void
    {
        if (!$this->access->mayAssignIn($app->teamId()->toRfc4122(), $projectId)) {
            throw PermissionDenied::for(Permission::ProjectAppsAssign->value);
        }
    }
}
