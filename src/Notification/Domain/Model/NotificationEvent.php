<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

/**
 * What a rule can notify (cadrage §8).
 */
enum NotificationEvent: string
{
    case VulnerabilityOpened = 'vulnerability_opened';
    case VulnerabilityFixed = 'vulnerability_fixed';
    case EndOfLife = 'end_of_life';
    case Outdated = 'outdated';
    case AgentSilent = 'agent_silent';
    case AgentPending = 'agent_pending';
    case AcceptanceExpiring = 'acceptance_expiring';

    /**
     * The events about a fleet only (agents): a project rule never receives them.
     */
    public function isFleetOnly(): bool
    {
        return \in_array($this, [self::AgentSilent, self::AgentPending], true);
    }
}
