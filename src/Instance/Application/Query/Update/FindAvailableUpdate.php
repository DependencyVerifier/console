<?php

declare(strict_types=1);

namespace Instance\Application\Query\Update;

use Instance\Domain\Model\AvailableUpdate;
use Shared\Application\Bus\Query;

/**
 * A newer release of the console than the running one, for the super admin only.
 *
 * @implements Query<?AvailableUpdate>
 */
final readonly class FindAvailableUpdate implements Query
{
}
