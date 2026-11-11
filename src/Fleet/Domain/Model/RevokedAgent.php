<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A revoked agent never comes back: a new installation is needed (protocol: agent_revoked).
 */
final class RevokedAgent extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('agent_revoked');
    }
}
