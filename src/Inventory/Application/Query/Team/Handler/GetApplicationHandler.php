<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Inventory\Application\Query\KeyPackages;
use Inventory\Application\Query\OsName;
use Inventory\Application\Query\Team\GetApplication;
use Inventory\Application\Query\View\ApplicationView;
use Inventory\Application\Security\InventoryAccess;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Symfony\Component\Uid\Uuid;

final readonly class GetApplicationHandler implements QueryHandler
{
    public function __construct(private InventoryAccess $access, private InventoryReadModel $read)
    {
    }

    public function __invoke(GetApplication $query): ApplicationView
    {
        $app = (Uuid::isValid($query->appId) ? $this->read->application($query->appId) : null) ?? throw ResourceNotFound::of('application');
        $scope = $this->access->scope($app->teamId);
        if (!$scope->seesEnvironment($app->environmentId)) {
            throw ResourceNotFound::of('application');
        }

        $assignable = array_values(array_filter($scope->environments, fn (TeamEnvironment $e): bool => $scope->seesProject($e->projectId) && $this->access->mayAssignIn($app->teamId, $e->projectId)));
        $current = $scope->environments[$app->environmentId ?? ''] ?? null;
        $image = null === $app->imageId ? null : $this->read->imageByImageId($app->teamId, $app->imageId);

        $distro = null === $image ? null : $this->read->distro($app->teamId, $image->sbomDigest);

        return new ApplicationView(
            $app->withEnvironment($scope->environmentName($app->environmentId)),
            $assignable,
            null !== $current && $this->access->mayAssignIn($app->teamId, $current->projectId),
            $image?->id,
            KeyPackages::of($this->read->packagesNamed($app->teamId, $app->sbomDigest, KeyPackages::names())),
            null !== $distro ? OsName::of($distro) : (null === $app->imageId ? $this->read->host($app->hostId)?->os() : null),
            $this->access->mayDocument($app->teamId, $current?->projectId),
        );
    }
}
