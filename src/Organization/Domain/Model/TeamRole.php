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
     * The project rank a team role gives in every project of its team, for the rank rules: a TEAM_MEMBER counts as a
     * PROJECT_MEMBER (decided 2026-11-11). A TEAM_ADMIN covers everything (canAssignProjectRole); the others give none.
     */
    public function impliedProjectRole(): ?ProjectRole
    {
        return self::Member === $this ? ProjectRole::Member : null;
    }

    /**
     * A TEAM_ADMIN covers every project role of its team; no other team role assigns project roles.
     */
    public function canAssignProjectRole(ProjectRole $role): bool
    {
        return self::Admin === $this;
    }
}
