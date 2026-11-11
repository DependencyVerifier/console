<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team;

use Inventory\Application\Query\View\AppRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The applications of a team the actor sees: of one project, not attached yet, of one host or one image.
 *
 * @implements Query<Page<AppRow>>
 */
final readonly class ListApplications implements Query
{
    public function __construct(
        public string $teamId,
        public PageRequest $page = new PageRequest(),
        public ?string $projectId = null,
        public bool $unattachedOnly = false,
        public ?string $hostId = null,
        public ?string $imageId = null,
    ) {
    }
}
