<?php

declare(strict_types=1);

namespace Shared\Domain\Authorization;

enum PermissionScope: string
{
    case Instance = 'instance';
    case Team = 'team';
    case Project = 'project';
}
