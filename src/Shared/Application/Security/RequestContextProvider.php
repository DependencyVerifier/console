<?php

declare(strict_types=1);

namespace Shared\Application\Security;

use Shared\Application\Audit\RequestContext;

interface RequestContextProvider
{
    public function current(): RequestContext;
}
