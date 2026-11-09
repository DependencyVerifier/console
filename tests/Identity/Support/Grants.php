<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Authorization\PermissionChecker;
use Shared\Domain\Authorization\Permission;

final class Grants implements PermissionChecker
{
    /** @var list<Permission> */
    public array $asked = [];

    /**
     * @param list<Permission> $granted
     */
    public function __construct(private readonly array $granted = [])
    {
    }

    public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
    {
        $this->asked[] = $permission;

        return \in_array($permission, $this->granted, true);
    }
}
