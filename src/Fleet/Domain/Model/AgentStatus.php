<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

/**
 * Console-side states of an agent (protocol §6.3). Pending and approved agents count in maxAgents.
 */
enum AgentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    /** No request for 7 days; the next valid request approves it again if the quota allows. */
    case Inactive = 'inactive';
    case Revoked = 'revoked';

    public function countsInQuota(): bool
    {
        return self::Pending === $this || self::Approved === $this;
    }
}
