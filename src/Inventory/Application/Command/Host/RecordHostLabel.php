<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Host;

use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * Keeps Inventory's copy of a host's label (Fleet's HostLabelled). No audit entry of its own: the human change is
 * audited by Fleet (fleet.label_host); an unexpected failure is still audited.
 */
final readonly class RecordHostLabel implements SelfAuditedCommand
{
    /**
     * @param list<string> $addresses
     */
    public function __construct(public string $hostId, public string $teamId, public ?string $displayName, public array $addresses)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('inventory.record_host_label');
    }

    public function auditTarget(): Target
    {
        return Target::of('host', $this->hostId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
