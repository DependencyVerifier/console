<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messenger;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Test-only command. `$behaviour`: rename | touch | rename_then_fail | permission | not_found | rule | technical |
 * lock | none.
 */
final readonly class FixtureCommand implements AuditableCommand
{
    public function __construct(public string $behaviour, public string $id = '', public string $name = '')
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fixture.'.$this->behaviour, ['behaviour']);
    }

    public function auditTarget(): Target
    {
        return '' === $this->id ? Target::none() : Target::of('fixture', $this->id);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['behaviour' => $this->behaviour]);
    }
}
