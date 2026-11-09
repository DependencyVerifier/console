<?php

declare(strict_types=1);

namespace Organization\Domain\Repository;

use Organization\Domain\Model\PermissionGrant;

interface PermissionGrantRepository
{
    public function add(PermissionGrant $grant): void;
}
