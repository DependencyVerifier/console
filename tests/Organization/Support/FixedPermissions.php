<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Organization\Application\Security\ActorPermissions;
use Organization\Domain\Authorization\EffectivePermissions;
use Organization\Domain\Authorization\ProjectGrant;
use Organization\Domain\Authorization\TeamGrant;

final class FixedPermissions implements ActorPermissions
{
    public EffectivePermissions $permissions;

    /**
     * @param list<TeamGrant>    $teams
     * @param list<ProjectGrant> $projects
     */
    public function __construct(bool $superAdmin = false, array $teams = [], array $projects = [])
    {
        $this->permissions = EffectivePermissions::resolve($superAdmin, $teams, $projects);
    }

    public static function superAdmin(): self
    {
        return new self(true);
    }

    public function current(): EffectivePermissions
    {
        return $this->permissions;
    }
}
