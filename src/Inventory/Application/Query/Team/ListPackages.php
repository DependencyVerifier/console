<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team;

use Inventory\Application\Query\View\PackageRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The packages of an application, an image or a host (its OS packages), from its current SBOM.
 *
 * @implements Query<Page<PackageRow>>
 */
final readonly class ListPackages implements Query
{
    public const string APPLICATION = 'application';
    public const string IMAGE = 'image';
    public const string HOST = 'host';

    public function __construct(public string $teamId, public string $of, public string $id, public PageRequest $page = new PageRequest())
    {
    }
}
