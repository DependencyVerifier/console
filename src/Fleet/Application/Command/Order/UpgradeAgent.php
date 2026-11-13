<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Order;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * "Upgrade": an UPGRADE_AGENT order to the latest verified release, for an approved host agent behind it (host.order).
 * Without `agentId`, every such agent of the team.
 */
final readonly class UpgradeAgent implements AuditableCommand
{
    public function __construct(public string $teamId, public ?string $agentId = null)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.upgrade_agent');
    }

    public function auditTarget(): Target
    {
        return null === $this->agentId ? Target::of('team', $this->teamId) : Target::of('agent', $this->agentId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
