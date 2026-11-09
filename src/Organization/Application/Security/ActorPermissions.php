<?php

declare(strict_types=1);

namespace Organization\Application\Security;

use Organization\Domain\Authorization\EffectivePermissions;

/**
 * Effective permissions of the current actor, computed from the database (never from the session).
 */
interface ActorPermissions
{
    public function current(): EffectivePermissions;
}
