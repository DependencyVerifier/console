<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A release of the agent not signed by the project release key, or whose binaries do not match their signed
 * checksums: never served (cadrage §6, install trust chain).
 */
final class InvalidAgentRelease extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('invalid_agent_release', $detail);
    }
}
