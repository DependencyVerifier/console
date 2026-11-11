<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Fleet;

use Shared\Application\Bus\Query;

/**
 * Published query of Fleet: what another context needs to know about an authenticated agent (Inventory: its team,
 * its host, whether it may send, the Compose project it was enrolled for, the default environment of its token).
 *
 * @implements Query<AgentContext>
 */
final readonly class GetAgentContext implements Query
{
    public function __construct(public string $agentId)
    {
    }
}
