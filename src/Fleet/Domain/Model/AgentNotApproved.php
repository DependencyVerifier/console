<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * Orders go to approved agents only.
 */
final class AgentNotApproved extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('agent_not_approved');
    }
}
