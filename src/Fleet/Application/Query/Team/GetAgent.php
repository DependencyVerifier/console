<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team;

use Fleet\Application\Query\View\AgentRow;
use Shared\Application\Bus\Query;

/**
 * One agent of a team (its approval dialog shows the fingerprint to compare with the installation's log).
 *
 * @implements Query<AgentRow>
 */
final readonly class GetAgent implements Query
{
    public function __construct(public string $agentId)
    {
    }
}
