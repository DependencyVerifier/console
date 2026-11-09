<?php

declare(strict_types=1);

namespace Shared\Application\Security;

use Shared\Application\Audit\Actor;

/**
 * The actor of the current request or command (identity only; the request context is a separate port).
 */
interface CurrentActor
{
    public function actor(): Actor;

    public function isSuperAdmin(): bool;
}
