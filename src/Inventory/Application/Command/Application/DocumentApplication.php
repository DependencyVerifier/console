<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Application;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Replaces the documentation links of an application: project.apps.assign on its project, host.edit on its team while
 * it is not attached. The audit keeps their number, not the URLs (they may be long).
 */
final readonly class DocumentApplication implements AuditableCommand
{
    /**
     * @param list<array{url: string, label: ?string}> $links
     */
    public function __construct(public string $appId, public array $links)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('inventory.document_application', ['links']);
    }

    public function auditTarget(): Target
    {
        return Target::of('application', $this->appId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['links' => \count($this->links)]);
    }
}
