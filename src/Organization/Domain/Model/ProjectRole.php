<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

/**
 * Project roles, ordered by level: PROJECT_ADMIN > PROJECT_MEMBER > PROJECT_VIEWER. A PROJECT_ADMIN may name another
 * PROJECT_ADMIN but never touches team roles.
 */
enum ProjectRole: string
{
    case Admin = 'project_admin';
    case Member = 'project_member';
    case Viewer = 'project_viewer';

    public function level(): int
    {
        return match ($this) {
            self::Admin => 3,
            self::Member => 2,
            self::Viewer => 1,
        };
    }

    public function canAssign(self $role): bool
    {
        return $role->level() <= $this->level();
    }
}
