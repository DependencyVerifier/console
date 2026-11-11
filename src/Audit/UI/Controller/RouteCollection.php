<?php

declare(strict_types=1);

namespace Audit\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case ADMIN_AUDIT = 'admin_audit';
    case TEAM_AUDIT = 'team_audit';

    public function prefixed(): string
    {
        return 'audit_'.$this->value;
    }
}
