<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Agent;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * POST /agent/v1/inventories (protocol §8.2), from an authenticated agent. Retrying the same manifest is idempotent.
 */
final readonly class SubmitManifest implements AuditableCommand
{
    public function __construct(public string $agentId, public string $inventoryId, #[\SensitiveParameter] public string $body)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('inventory.submit_manifest', ['agent_id']);
    }

    public function auditTarget(): Target
    {
        return Target::of('inventory', $this->inventoryId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['agent_id' => $this->agentId]);
    }
}
