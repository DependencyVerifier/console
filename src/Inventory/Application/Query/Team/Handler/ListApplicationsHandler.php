<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Inventory\Application\Query\Team\ListApplications;
use Inventory\Application\Query\View\AppRow;
use Inventory\Application\Security\InventoryAccess;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

/**
 * A TEAM_GUEST sees the applications of its projects only, never the ones not attached yet.
 */
final readonly class ListApplicationsHandler implements QueryHandler
{
    public function __construct(private InventoryAccess $access, private InventoryReadModel $read)
    {
    }

    /**
     * @return Page<AppRow>
     */
    public function __invoke(ListApplications $query): Page
    {
        $scope = $this->access->scope($query->teamId);
        $environments = $scope->visibleEnvironments;
        if (null !== $query->projectId) {
            if (!$scope->seesProject($query->projectId)) {
                throw ResourceNotFound::of('project');
            }

            $environments = $scope->environmentsOf($query->projectId);
        }

        if (null !== $query->environmentId) {
            if (!isset($scope->environments[$query->environmentId]) || !$scope->seesEnvironment($query->environmentId)) {
                throw ResourceNotFound::of('environment');
            }

            $environments = [$query->environmentId];
        }

        $unattached = $query->unattachedOnly && $scope->seesAll();
        if ($query->unattachedOnly && !$unattached) {
            return new Page([], 0, 0);
        }

        $page = $this->read->applications($query->teamId, $unattached ? null : $environments, $unattached, $query->hostId, $query->imageId, $query->page);

        return new Page(array_map(static fn (AppRow $row): AppRow => $row->withEnvironment($scope->environmentName($row->environmentId)), $page->items), $page->filtered, $page->total);
    }
}
