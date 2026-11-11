<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A host is deleted only once every agent on it is revoked.
 */
final class HostHasAgents extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('host_has_agents');
    }
}
