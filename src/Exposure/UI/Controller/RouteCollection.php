<?php

declare(strict_types=1);

namespace Exposure\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case FINDING = 'finding';
    case FINDING_ACCEPT = 'finding_accept';
    case FINDING_REVOKE = 'finding_revoke';

    public function prefixed(): string
    {
        return 'exposure_'.$this->value;
    }
}
