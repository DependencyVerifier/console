<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

enum GrantChange: string
{
    case RoleAssigned = 'role_assigned';
    case RoleChanged = 'role_changed';
    case ExtraGranted = 'extra_granted';
    case ExtraRevoked = 'extra_revoked';
    case Removed = 'removed';
}
