<?php

declare(strict_types=1);

namespace Notification\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case UNSUBSCRIBE = 'unsubscribe';

    public function prefixed(): string
    {
        return 'notification_'.$this->value;
    }
}
