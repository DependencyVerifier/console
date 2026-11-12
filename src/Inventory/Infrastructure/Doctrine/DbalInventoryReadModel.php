<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Inventory\Application\Query\InventoryReadModel;
use Inventory\Application\Query\View\AppRow;
use Inventory\Application\Query\View\HistoryRow;
use Inventory\Application\Query\View\HostView;
use Inventory\Application\Query\View\ImageView;
use Inventory\Application\Query\View\PackageRow;
use Inventory\Domain\Model\SnapshotSubject;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Shared\Infrastructure\Doctrine\Row;

use function Symfony\Component\String\u;

final readonly class DbalInventoryReadModel implements InventoryReadModel
{
    private const string NO_ID = '00000000-0000-0000-0000-000000000000';
    private const string PACKAGES_OF = '(SELECT COUNT(*) FROM inventory_package p JOIN inventory_blob b ON b.id = p.blob_id WHERE b.team_id = %s AND b.digest = %s)';

    public function __construct(private Connection $connection)
    {
    }

    public function applications(string $teamId, ?array $environmentIds, bool $unattachedOnly, ?string $hostId, ?string $imageId, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('inventory_app_instance', 'a')
            ->leftJoin('a', 'inventory_host', 'h', 'h.host_id = a.host_id')
            ->where('a.team_id = :team')->setParameter('team', $teamId);
        if ($unattachedOnly) {
            $base->andWhere('a.environment_id IS NULL');
        } elseif (null !== $environmentIds) {
            $base->andWhere('a.environment_id IN (:environments)')->setParameter('environments', [] === $environmentIds ? [self::NO_ID] : $environmentIds, ArrayParameterType::STRING);
        }

        if (null !== $hostId) {
            $base->andWhere('a.host_id = :host')->setParameter('host', $hostId);
        }

        if (null !== $imageId) {
            $base->andWhere('a.image_id = :image')->setParameter('image', $imageId);
        }

        $rows = $this->page($base, $page, static fn (QueryBuilder $q) => $q->andWhere("(LOWER(a.subject_key) LIKE :search OR LOWER(COALESCE(h.facts->>'hostname', '')) LIKE :search)"),
            ['name' => 'a.subject_key', 'host' => "h.facts->>'hostname'", 'last_seen' => 'a.last_seen_at'], 'name',
            static fn (QueryBuilder $q) => $q->select(...self::appColumns()), 'a.id');

        return new Page(array_map(self::app(...), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function application(string $appId): ?AppRow
    {
        $row = $this->connection->createQueryBuilder()->select(...self::appColumns())->from('inventory_app_instance', 'a')
            ->leftJoin('a', 'inventory_host', 'h', 'h.host_id = a.host_id')->where('a.id = :id')->setParameter('id', $appId)->fetchAssociative();

        return false === $row ? null : self::app($row);
    }

    public function counts(string $teamId, ?array $environmentIds): array
    {
        $parameters = ['team' => $teamId];
        $types = [];
        $visible = 'TRUE';
        if (null !== $environmentIds) {
            $visible = 'a.environment_id IN (:environments)';
            $parameters['environments'] = [] === $environmentIds ? [self::NO_ID] : $environmentIds;
            $types['environments'] = ArrayParameterType::STRING;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE a.present AND '.$visible.') AS applications, COUNT(*) FILTER (WHERE a.present AND a.environment_id IS NULL) AS unattached,
                (SELECT COUNT(*) FROM inventory_container_image i WHERE i.team_id = :team) AS images
             FROM inventory_app_instance a WHERE a.team_id = :team',
            $parameters,
            $types,
        );
        \assert(false !== $row);

        return ['applications' => Row::int($row['applications']), 'unattached' => Row::int($row['unattached']), 'images' => Row::int($row['images'])];
    }

    public function packages(string $teamId, string $digest, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('inventory_package', 'p')->innerJoin('p', 'inventory_blob', 'b', 'b.id = p.blob_id')
            ->where('b.team_id = :team')->andWhere('b.digest = :digest')->setParameter('team', $teamId)->setParameter('digest', $digest);
        $rows = $this->page($base, $page, static fn (QueryBuilder $q) => $q->andWhere('LOWER(p.purl) LIKE :search'),
            ['name' => 'p.name', 'type' => 'p.type'], 'name', static fn (QueryBuilder $q) => $q->select('p.purl', 'p.type', 'p.namespace', 'p.name', 'p.version', 'p.dependency', 'p.manager'), 'p.id');

        return new Page(array_map(static fn (array $row): PackageRow => new PackageRow(
            Row::string($row['purl']), Row::string($row['type']), Row::nullableString($row['namespace']), Row::string($row['name']),
            Row::nullableString($row['version']), Row::nullableString($row['dependency']), Row::nullableString($row['manager']),
        ), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function history(array $subjects, string $subjectId, PageRequest $page): Page
    {
        $parameters = ['subject' => $subjectId, 'types' => array_map(static fn (SnapshotSubject $s): string => $s->value, $subjects)];
        $types = ['types' => ArrayParameterType::STRING];
        $total = Row::int($this->connection->fetchOne('SELECT COUNT(*) FROM inventory_snapshot s WHERE s.subject_id = :subject AND s.subject_type IN (:types)', $parameters, $types));
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM (
                SELECT s.id, s.taken_at, s.subject_type, s.digest, r.manifest->>'trigger' AS trigger,
                    LAG(s.digest) OVER (PARTITION BY s.subject_type ORDER BY s.taken_at, s.id) IS DISTINCT FROM s.digest AS changed
                FROM inventory_snapshot s LEFT JOIN inventory_run r ON r.id = s.inventory_id
                WHERE s.subject_id = :subject AND s.subject_type IN (:types)
            ) h ORDER BY h.taken_at DESC, h.id DESC LIMIT :limit OFFSET :offset",
            [...$parameters, 'limit' => $page->limit, 'offset' => $page->offset],
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return new Page(array_map(static fn (array $row): HistoryRow => new HistoryRow(
            new \DateTimeImmutable(Row::string($row['taken_at'])), Row::string($row['subject_type']), Row::string($row['digest']), (bool) $row['changed'], Row::nullableString($row['trigger']),
        ), $rows), $total, $total);
    }

    public function host(string $hostId): ?HostView
    {
        $row = $this->connection->fetchAssociative(
            'SELECT h.host_id, h.team_id, h.facts, h.packages_digest, h.inventoried_at, '.\sprintf(self::PACKAGES_OF, 'h.team_id', 'h.packages_digest').' AS packages FROM inventory_host h WHERE h.host_id = :id',
            ['id' => $hostId],
        );
        if (false === $row) {
            return null;
        }

        $facts = self::json($row['facts']);

        return new HostView(
            Row::string($row['host_id']), Row::string($row['team_id']), \is_string($facts['hostname'] ?? null) ? $facts['hostname'] : '', $facts,
            Row::nullableString($row['packages_digest']), null === Row::nullableString($row['inventoried_at']) ? null : new \DateTimeImmutable(Row::string($row['inventoried_at'])), Row::int($row['packages']),
        );
    }

    public function image(string $imageRecordId): ?ImageView
    {
        return $this->imageWhere('i.id = :value', $imageRecordId, null);
    }

    public function imageByImageId(string $teamId, string $imageId): ?ImageView
    {
        return $this->imageWhere('i.image_id = :value', $imageId, $teamId);
    }

    private function imageWhere(string $condition, string $value, ?string $teamId): ?ImageView
    {
        $query = $this->connection->createQueryBuilder()
            ->select('i.id', 'i.team_id', 'i.image_id', 'i.fields', 'i.sbom_digest', 'i.last_seen_at', \sprintf(self::PACKAGES_OF, 'i.team_id', 'i.sbom_digest').' AS packages')
            ->from('inventory_container_image', 'i')->where($condition)->setParameter('value', $value);
        if (null !== $teamId) {
            $query->andWhere('i.team_id = :team')->setParameter('team', $teamId);
        }

        $row = $query->fetchAssociative();
        if (false === $row) {
            return null;
        }

        $fields = self::json($row['fields']);
        $strings = static fn (mixed $values): array => \is_array($values) ? array_values(array_filter($values, \is_string(...))) : [];

        return new ImageView(
            Row::string($row['id']), Row::string($row['team_id']), Row::string($row['image_id']), $strings($fields['tags'] ?? []), $strings($fields['repo_digests'] ?? []),
            \is_string($fields['provenance'] ?? null) ? $fields['provenance'] : null, \is_string($fields['base_image'] ?? null) ? $fields['base_image'] : null,
            new \DateTimeImmutable(Row::string($row['last_seen_at'])), Row::string($row['sbom_digest']), Row::int($row['packages']),
        );
    }

    /**
     * @return list<string>
     */
    private static function appColumns(): array
    {
        return ['a.id', 'a.team_id', 'a.host_id', "COALESCE(h.facts->>'hostname', '') AS hostname", 'a.subject_key', 'a.kind', 'a.fields', 'a.image_id', 'a.environment_id',
            'a.assignment', 'a.present', 'a.last_seen_at', 'a.sbom_digest', \sprintf(self::PACKAGES_OF, 'a.team_id', 'a.sbom_digest').' AS packages'];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function app(array $row): AppRow
    {
        return new AppRow(
            Row::string($row['id']), Row::string($row['team_id']), Row::string($row['host_id']), Row::string($row['hostname']), Row::string($row['subject_key']),
            Row::string($row['kind']), self::json($row['fields']), Row::nullableString($row['image_id']), Row::nullableString($row['environment_id']),
            Row::string($row['assignment']), (bool) $row['present'], new \DateTimeImmutable(Row::string($row['last_seen_at'])), Row::string($row['sbom_digest']), Row::int($row['packages']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(mixed $value): array
    {
        $decoded = \is_string($value) ? json_decode($value, true) : null;

        /** @var array<string, mixed> */
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param \Closure(QueryBuilder): mixed $search
     * @param array<string, string>         $sortable
     * @param \Closure(QueryBuilder): mixed $select
     *
     * @return array{items: list<array<string, mixed>>, filtered: int, total: int}
     */
    private function page(QueryBuilder $base, PageRequest $page, \Closure $search, array $sortable, string $defaultSort, \Closure $select, string $tieBreaker): array
    {
        $total = Row::int((clone $base)->select('COUNT(*)')->fetchOne());
        $filtered = clone $base;
        if ('' !== $page->search) {
            $filtered->setParameter('search', '%'.u($page->search)->lower()->replace('\\', '\\\\')->replace('%', '\\%')->replace('_', '\\_')->toString().'%');
            $search($filtered);
        }

        $count = Row::int((clone $filtered)->select('COUNT(*)')->fetchOne());
        $items = clone $filtered;
        $select($items);
        $items->orderBy($sortable[$page->sortAmong(array_keys($sortable), $defaultSort)], $page->descending ? 'DESC' : 'ASC')
            ->addOrderBy($tieBreaker, $page->descending ? 'DESC' : 'ASC')
            ->setFirstResult($page->offset)->setMaxResults($page->limit);

        /** @var list<array<string, mixed>> $rows */
        $rows = $items->fetchAllAssociative();

        return ['items' => $rows, 'filtered' => $count, 'total' => $total];
    }
}
