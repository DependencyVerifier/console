<?php

declare(strict_types=1);

namespace Shared\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case HEALTHCHECK = 'healthcheck';
    case PREVIEW_HOST = 'preview_host';
    case PREVIEW_LOGIN = 'preview_login';

    public function prefixed(): string
    {
        return 'shared_'.$this->value;
    }
}
