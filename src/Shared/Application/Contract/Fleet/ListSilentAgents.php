<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Fleet;

use Shared\Application\Bus\Query;

/**
 * Published query of Fleet: the approved agents not heard from since a date, the oldest silence first.
 *
 * @implements Query<list<SilentAgent>>
 */
final readonly class ListSilentAgents implements Query
{
    public function __construct(public \DateTimeImmutable $since, public int $limit = 1000)
    {
    }
}
