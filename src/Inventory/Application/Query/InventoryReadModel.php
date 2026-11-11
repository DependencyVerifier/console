<?php

declare(strict_types=1);

namespace Inventory\Application\Query;

use Inventory\Application\Query\View\AppRow;
use Inventory\Application\Query\View\HostView;
use Inventory\Application\Query\View\ImageView;
use Inventory\Application\Query\View\PackageRow;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * Read side of Inventory (DBAL). It filters by what it is given; the handlers decide what the actor may see.
 */
interface InventoryReadModel
{
    /**
     * @param list<string>|null $environmentIds null: any environment (attached or not)
     *
     * @return Page<AppRow> rows with an empty environment name (filled by the handler)
     */
    public function applications(string $teamId, ?array $environmentIds, bool $unattachedOnly, ?string $hostId, ?string $imageId, PageRequest $page): Page;

    public function application(string $appId): ?AppRow;

    /**
     * @return Page<PackageRow>
     */
    public function packages(string $teamId, string $digest, PageRequest $page): Page;

    public function host(string $hostId): ?HostView;

    public function image(string $imageRecordId): ?ImageView;

    public function imageByImageId(string $teamId, string $imageId): ?ImageView;
}
