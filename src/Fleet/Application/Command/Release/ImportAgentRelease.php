<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Release;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A release of the agent, from GitHub (`make agent-release-sync`) or imported by hand (`make agent-release-import`):
 * stored only if its checksums are signed by the project release key and its binaries match them.
 */
final readonly class ImportAgentRelease implements AuditableCommand
{
    /**
     * @param array<string, string> $binaries file name => bytes
     */
    public function __construct(public string $checksums, public string $signature, public array $binaries)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.import_agent_release');
    }

    public function auditTarget(): Target
    {
        return Target::none();
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
