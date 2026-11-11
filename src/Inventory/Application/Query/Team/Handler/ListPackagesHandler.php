<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Inventory\Application\Query\Team\GetApplication;
use Inventory\Application\Query\Team\ListPackages;
use Inventory\Application\Query\View\PackageRow;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

final readonly class ListPackagesHandler implements QueryHandler
{
    public function __construct(private InventoryVisibility $visibility, private GetApplicationHandler $applications, private InventoryReadModel $read)
    {
    }

    /**
     * @return Page<PackageRow>
     */
    public function __invoke(ListPackages $query): Page
    {
        $digest = match ($query->of) {
            ListPackages::APPLICATION => $this->applicationDigest($query->teamId, $query->id),
            ListPackages::IMAGE => $this->visibility->image($query->teamId, $query->id)->sbomDigest,
            ListPackages::HOST => $this->visibility->host($query->teamId, $query->id)->packagesDigest,
            default => throw ResourceNotFound::of('packages'),
        };

        return null === $digest ? new Page([], 0, 0) : $this->read->packages($query->teamId, $digest, $query->page);
    }

    private function applicationDigest(string $teamId, string $appId): string
    {
        $app = ($this->applications)(new GetApplication($appId))->app;
        if ($app->teamId !== $teamId) {
            throw ResourceNotFound::of('application');
        }

        return $app->sbomDigest;
    }
}
