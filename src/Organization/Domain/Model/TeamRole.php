<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

/**
 * Team roles, ordered by level (decision 15): TEAM_ADMIN > TEAM_MEMBER > TEAM_VIEWER > TEAM_GUEST. A role may grant a
 * role lower than or equal to its own.
 */
enum TeamRole: string
{
    case Admin = 'team_admin';
    case Member = 'team_member';
    case Viewer = 'team_viewer';
    case Guest = 'team_guest';

    public function level(): int
    {
        return match ($this) {
            self::Admin => 4,
            self::Member => 3,
            self::Viewer => 2,
            self::Guest => 1,
        };
    }

    public function canAssign(self $role): bool
    {
        return $role->level() <= $this->level();
    }

    /**
     * A TEAM_ADMIN covers every project role of its team; no other team role assigns project roles.
     */
    public function canAssignProjectRole(ProjectRole $role): bool
    {
        return self::Admin === $this;
    }
}
