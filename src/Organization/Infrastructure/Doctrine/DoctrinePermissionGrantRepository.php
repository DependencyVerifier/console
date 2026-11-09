<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Doctrine;

use Organization\Domain\Model\PermissionGrant;
use Organization\Domain\Repository\PermissionGrantRepository;

final readonly class DoctrinePermissionGrantRepository implements PermissionGrantRepository
{
    public function __construct(private OrganizationManager $manager)
    {
    }

    public function add(PermissionGrant $grant): void
    {
        $this->manager->get()->persist($grant);
    }
}
