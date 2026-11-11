<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Agent;

use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * PUT /agent/v1/inventories/{id}/snapshots/{digest} (protocol §8.3). An upload writes no audit entry (one per
 * snapshot would add nothing to the manifest's and the completion's); its refusals are audited by the bus.
 */
final readonly class StoreSnapshot implements SelfAuditedCommand
{
    public function __construct(
        public string $agentId,
        public string $inventoryId,
        public string $digest,
        public string $contentType,
        #[\SensitiveParameter] public string $gzip,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('inventory.store_snapshot', ['digest']);
    }

    public function auditTarget(): Target
    {
        return Target::of('inventory', $this->inventoryId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['digest' => $this->digest]);
    }
}
