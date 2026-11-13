<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Exposure;

use Shared\Application\Bus\Query;

/**
 * Published query of Exposure: the running risk acceptances whose expiry falls in [from, to].
 *
 * @implements Query<list<ExpiringAcceptance>>
 */
final readonly class ListExpiringAcceptances implements Query
{
    public function __construct(public \DateTimeImmutable $from, public \DateTimeImmutable $to)
    {
    }
}
