<?php

declare(strict_types=1);

namespace Organization\Application\Command\Environment;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Deletes an environment without content.
 */
final readonly class DeleteEnvironment implements AuditableCommand
{
    public function __construct(
        public string $environmentId,
        public string $projectId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.delete_environment');
    }

    public function auditTarget(): Target
    {
        return Target::of('environment', $this->environmentId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
