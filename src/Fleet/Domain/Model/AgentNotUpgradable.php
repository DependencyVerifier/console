<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * An agent in a container (its image is upgraded by its tag), already at that version or newer, or no release known.
 */
final class AgentNotUpgradable extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('agent_not_upgradable', 'Only a host agent older than the release can be upgraded.');
    }
}
