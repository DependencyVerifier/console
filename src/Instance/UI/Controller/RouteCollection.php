<?php

declare(strict_types=1);

namespace Instance\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case ABOUT = 'about';
    case CONSOLE_UPDATE_FRAME = 'console_update_frame';

    public function prefixed(): string
    {
        return 'instance_'.$this->value;
    }
}
