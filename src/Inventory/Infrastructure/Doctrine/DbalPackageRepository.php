<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Inventory\Domain\Repository\PackageRepository;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * `inventory_package`, a raw SQL table (bulk inserts of thousands of rows per SBOM, excluded from the ORM schema).
 */
final readonly class DbalPackageRepository implements PackageRepository
{
    private const int CHUNK = 500;

    public function __construct(private Connection $connection)
    {
    }

    public function hasPackagesOf(Uuid $blobId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM inventory_package WHERE blob_id = :blob LIMIT 1', ['blob' => $blobId->toRfc4122()]);
    }

    public function store(Uuid $teamId, Uuid $blobId, array $packages): void
    {
        foreach (array_chunk($packages, self::CHUNK) as $chunk) {
            $rows = [];
            $parameters = [];
            foreach ($chunk as $package) {
                $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $parameters,
                    Uuid::v7()->toRfc4122(), $teamId->toRfc4122(), $blobId->toRfc4122(), $package->purl->value, $package->purl->type,
                    $package->purl->namespace, $package->purl->name, $package->purl->version, $package->dependency, $package->source, $package->manager,
                );
            }

            $this->connection->executeStatement(
                'INSERT INTO inventory_package (id, team_id, blob_id, purl, type, namespace, name, version, dependency, source, manager) VALUES '.u(', ')->join($rows)->toString(),
                $parameters,
            );
        }
    }
}
