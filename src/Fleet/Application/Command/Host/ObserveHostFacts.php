<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Host;

use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * Merges the facts an inventory observed into a host, and promotes its identity to its Docker engine when it was only
 * known by its hostname. Copies Inventory's HostFactsObserved (a boundary DTO). Self-audited: no entry per inventory,
 * a single entry when the identity is promoted (by whom: the agent of the run; never the engine id itself).
 */
final readonly class ObserveHostFacts implements SelfAuditedCommand
{
    public function __construct(
        public string $hostId,
        public string $teamId,
        public string $agentId,
        public ?string $hostname,
        public ?string $osId,
        public ?string $osVersionId,
        public ?string $osPrettyName,
        public ?string $kernel,
        public ?string $arch,
        public ?string $dockerEngineId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.host_identity_promoted', ['agent_id', 'from_kind', 'to_kind']);
    }

    public function auditTarget(): Target
    {
        return Target::of('host', $this->hostId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['agent_id' => $this->agentId, 'from_kind' => 'hostname', 'to_kind' => 'docker']);
    }
}
