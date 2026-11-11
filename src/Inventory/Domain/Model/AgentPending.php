<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * An agent waiting for approval sends no inventory (protocol §6.3): it keeps polling.
 */
final class AgentPending extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('agent_pending');
    }
}
