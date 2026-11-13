<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Host;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

use function Symfony\Component\String\u;

/**
 * Gives a host a display name (empty: its hostname again) and its IP addresses (host.edit). The audit keeps the name
 * asked (bounded) and the number of addresses.
 */
final readonly class LabelHost implements AuditableCommand
{
    /**
     * @param list<string> $addresses
     */
    public function __construct(public string $hostId, public ?string $displayName, public array $addresses)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.label_host', ['display_name', 'addresses']);
    }

    public function auditTarget(): Target
    {
        return Target::of('host', $this->hostId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['display_name' => u($this->displayName ?? '')->truncate(100)->toString(), 'addresses' => \count($this->addresses)]);
    }
}
