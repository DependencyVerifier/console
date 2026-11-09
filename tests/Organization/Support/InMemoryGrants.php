<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Organization\Domain\Model\PermissionGrant;
use Organization\Domain\Repository\PermissionGrantRepository;

final class InMemoryGrants implements PermissionGrantRepository
{
    /** @var list<PermissionGrant> */
    public array $grants = [];

    public function add(PermissionGrant $grant): void
    {
        $this->grants[] = $grant;
    }
}
