<?php

declare(strict_types=1);

namespace Notification\Application\Query;

use Shared\Application\Bus\Query;

/**
 * The digests to send now (`make notifications-digest`, daily): the daily ones, and on Monday the weekly ones.
 *
 * @implements Query<list<Digest>>
 */
final readonly class ListDigests implements Query
{
    public function __construct(public int $limit = 5000)
    {
    }
}
