<?php

declare(strict_types=1);

namespace Notification\Application\Query;

use Shared\Application\Bus\Query;

/**
 * The immediate deliveries due again (`make notifications-check`), the oldest first.
 *
 * @implements Query<list<string>>
 */
final readonly class ListDueDeliveries implements Query
{
    public function __construct(public int $limit = 500)
    {
    }
}
