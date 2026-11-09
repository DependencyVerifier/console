<?php

declare(strict_types=1);

namespace Organization\Domain\Authorization;

use Organization\Domain\Model\TeamRole;
use Shared\Domain\Authorization\Permission;

/**
 * An active team membership as the resolver sees it.
 */
final readonly class TeamGrant
{
    /**
     * @param list<Permission> $extras
     */
    public function __construct(public string $teamId, public TeamRole $role, public array $extras = [])
    {
    }
}
