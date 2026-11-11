<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team\Handler;

use Inventory\Application\Query\Team\GetImage;
use Inventory\Application\Query\View\ImageView;
use Shared\Application\Bus\QueryHandler;

final readonly class GetImageHandler implements QueryHandler
{
    public function __construct(private InventoryVisibility $visibility)
    {
    }

    public function __invoke(GetImage $query): ImageView
    {
        return $this->visibility->image($query->teamId, $query->imageRecordId);
    }
}
