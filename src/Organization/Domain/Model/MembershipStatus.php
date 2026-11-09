<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

/**
 * A suspended membership (account deactivated) counts neither for `maxMembers` nor for "at least one TEAM_ADMIN".
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
