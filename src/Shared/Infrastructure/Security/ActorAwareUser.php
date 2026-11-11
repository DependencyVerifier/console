<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Security;

use Shared\Application\Audit\Actor;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A security user that is not an account (an enrolled agent): it tells the actor its writes are audited for.
 */
interface ActorAwareUser extends UserInterface
{
    public function actor(): Actor;
}
