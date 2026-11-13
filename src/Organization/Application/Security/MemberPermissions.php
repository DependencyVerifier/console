<?php

declare(strict_types=1);

namespace Organization\Application\Security;

use Organization\Domain\Authorization\EffectivePermissions;

/**
 * The permissions of any account through its active memberships (not the actor's: a recipient of a notification).
 */
interface MemberPermissions
{
    public function of(string $userId): EffectivePermissions;
}
