<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team;

use Inventory\Application\Query\View\ImageView;
use Shared\Application\Bus\Query;

/**
 * @implements Query<ImageView>
 */
final readonly class GetImage implements Query
{
    public function __construct(public string $teamId, public string $imageRecordId)
    {
    }
}
