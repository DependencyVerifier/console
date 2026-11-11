<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Agent;

use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * POST /agent/v1/poll (protocol §6), once the request is authenticated (signature, nonce, agent not revoked): the
 * agent is alive, its order results are applied, its queued orders are signed. A plain poll writes no audit entry
 * (one every 5 minutes per agent would drown the log); its refusals and failures are audited by the bus.
 */
final readonly class RecordAgentPoll implements SelfAuditedCommand
{
    public function __construct(public string $agentId, #[\SensitiveParameter] public string $body)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.poll');
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
