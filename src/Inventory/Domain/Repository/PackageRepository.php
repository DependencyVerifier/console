<?php

declare(strict_types=1);

namespace Inventory\Domain\Repository;

use Inventory\Domain\Model\Package;
use Symfony\Component\Uid\Uuid;

/**
 * The packages of an SBOM, extracted once per blob (bulk insert, never changed, deleted with their blob).
 */
interface PackageRepository
{
    public function hasPackagesOf(Uuid $blobId): bool;

    /**
     * @param list<Package> $packages
     */
    public function store(Uuid $teamId, Uuid $blobId, array $packages): void;
}
