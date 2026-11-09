<?php

declare(strict_types=1);

namespace Organization\Application\Command\Instance;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Default quotas of the instance, copied into the teams created afterwards only.
 */
final readonly class UpdateDefaultQuotas implements AuditableCommand
{
    /**
     * @param array<string, ?int> $quotas keyed by QuotaName value (null: unlimited)
     */
    public function __construct(
        public array $quotas,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.update_default_quotas');
    }

    public function auditTarget(): Target
    {
        return Target::of('instance_settings', 'instance');
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
