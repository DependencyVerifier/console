<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Domain\Model\Package;
use Inventory\Domain\Repository\PackageRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryPackages implements PackageRepository
{
    /** @var array<string, list<Package>> blob id => packages */
    public array $packages = [];

    public function hasPackagesOf(Uuid $blobId): bool
    {
        return isset($this->packages[$blobId->toRfc4122()]);
    }

    public function store(Uuid $teamId, Uuid $blobId, array $packages): void
    {
        $this->packages[$blobId->toRfc4122()] = [...$this->packages[$blobId->toRfc4122()] ?? [], ...$packages];
    }
}
