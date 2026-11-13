<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team;

use Fleet\Application\Query\View\HostLabelView;
use Shared\Application\Bus\Query;

/**
 * The display name and addresses of a host of a team, to edit them (host.edit).
 *
 * @implements Query<HostLabelView>
 */
final readonly class GetHostLabel implements Query
{
    public function __construct(public string $teamId, public string $hostId)
    {
    }
}
