<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Agent;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * An agent executing ROTATE_KEY announces its new public key (protocol §5.3), in a request signed by its current key,
 * with the proof that the new key signs. Audited: the identity of an agent changes.
 */
final readonly class RecordKeyRotation implements AuditableCommand
{
    public function __construct(public string $agentId, #[\SensitiveParameter] public string $body)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.rotate_agent_key');
    }

    public function auditTarget(): Target
    {
        return Target::of('agent', $this->agentId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
