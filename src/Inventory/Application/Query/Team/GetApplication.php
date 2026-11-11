<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team;

use Inventory\Application\Query\View\ApplicationView;
use Shared\Application\Bus\Query;

/**
 * @implements Query<ApplicationView>
 */
final readonly class GetApplication implements Query
{
    public function __construct(public string $appId)
    {
    }
}
