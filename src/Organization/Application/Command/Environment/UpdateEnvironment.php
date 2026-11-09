<?php

declare(strict_types=1);

namespace Organization\Application\Command\Environment;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Renames an environment or changes its production flag.
 */
final readonly class UpdateEnvironment implements AuditableCommand
{
    public function __construct(
        public string $environmentId,
        public string $projectId,
        public string $name,
        public bool $production,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.update_environment');
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
