<?php

declare(strict_types=1);

namespace Shared\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case HEALTHCHECK = 'healthcheck';
    case HOME = 'home';

    public function prefixed(): string
    {
        return 'shared_'.$this->value;
    }
}
