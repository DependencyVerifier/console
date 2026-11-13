<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A console key rotation needs the next key (APP_AGENT_SIGNING_KEY_NEXT), different from the current one.
 */
final class ConsoleKeyRotationNotPrepared extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('console_key_rotation_not_prepared', 'Set APP_AGENT_SIGNING_KEY_NEXT to a new key first.');
    }
}
