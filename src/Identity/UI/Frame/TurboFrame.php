<?php

declare(strict_types=1);

namespace Identity\UI\Frame;

use Identity\UI\Controller\RouteCollection;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Routing\RouteReference;

enum TurboFrame: string implements Frame
{
    case AdminUsers = 'admin-users';

    public function id(): string
    {
        return $this->value;
    }

    public function route(): string
    {
        return match ($this) {
            self::AdminUsers => RouteCollection::ADMIN_USERS_FRAME->prefixed(),
        };
    }

    public function fallbackRoute(FrameContext $context): RouteReference
    {
        return match ($this) {
            self::AdminUsers => new RouteReference(RouteCollection::ADMIN_USERS->prefixed()),
        };
    }
}
