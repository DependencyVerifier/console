<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Order;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * New collection settings for an agent: the next configuration version, sent as a SET_CONFIG order (and again by the
 * poll while the agent reports an older version).
 */
final readonly class ConfigureAgent implements AuditableCommand
{
    /**
     * @param list<string> $discoveryRoots
     * @param list<string> $declaredPaths
     * @param list<string> $exclusions
     */
    public function __construct(
        public string $orderId,
        public string $agentId,
        public int $scanIntervalMinutes,
        public array $discoveryRoots = [],
        public array $declaredPaths = [],
        public array $exclusions = [],
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.configure_agent', ['order_id', 'scan_interval_minutes']);
    }

    public function auditTarget(): Target
    {
        return Target::of('agent', $this->agentId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['order_id' => $this->orderId, 'scan_interval_minutes' => $this->scanIntervalMinutes]);
    }
}
