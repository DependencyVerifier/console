<?php

declare(strict_types=1);

namespace Organization\Application\Command\Environment;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Adds an environment to a project (quota maxEnvironmentsPerProject, unique name per project). Audited on the project:
 * a refused creation has no environment to resolve its scope from.
 */
final readonly class CreateEnvironment implements AuditableCommand
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
        return AuditAction::named('organization.create_environment', ['environment_id']);
    }

    public function auditTarget(): Target
    {
        return Target::of('project', $this->projectId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['environment_id' => $this->environmentId]);
    }
}
