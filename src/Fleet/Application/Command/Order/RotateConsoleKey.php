<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Order;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * The rotation of the console key (`make console-key-rotate`): a ROTATE_CONSOLE_KEY order announcing the next key
 * (APP_AGENT_SIGNING_KEY_NEXT), signed with the key it pins today, for every approved agent not on it yet.
 */
final readonly class RotateConsoleKey implements AuditableCommand
{
    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.rotate_console_key');
    }

    public function auditTarget(): Target
    {
        return Target::of('instance', 'console-key');
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
