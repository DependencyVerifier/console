<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * Only a pending agent can be approved.
 */
final class AgentNotPending extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('agent_not_pending');
    }
}
