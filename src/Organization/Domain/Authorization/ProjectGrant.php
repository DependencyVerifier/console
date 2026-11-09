<?php

declare(strict_types=1);

namespace Organization\Domain\Authorization;

use Organization\Domain\Model\ProjectRole;
use Shared\Domain\Authorization\Permission;

/**
 * An active project membership as the resolver sees it.
 */
final readonly class ProjectGrant
{
    /**
     * @param list<Permission> $extras
     */
    public function __construct(public string $projectId, public string $teamId, public ProjectRole $role, public array $extras = [])
    {
    }
}
