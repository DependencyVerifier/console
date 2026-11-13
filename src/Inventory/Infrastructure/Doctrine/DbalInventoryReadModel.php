<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Inventory\Application\Query\InventoryReadModel;
use Inventory\Application\Query\OsName;
use Inventory\Application\Query\View\AppRow;
use Inventory\Application\Query\View\HistoryRow;
use Inventory\Application\Query\View\HostView;
use Inventory\Application\Query\View\ImageView;
use Inventory\Application\Query\View\MatrixEntry;
use Inventory\Application\Query\View\MatrixRow;
use Inventory\Application\Query\View\PackageRow;
use Inventory\Domain\Model\SnapshotSubject;
use Shared\Application\Contract\Inventory\TeamSubject;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Shared\Infrastructure\Doctrine\Row;

use function Symfony\Component\String\u;

final readonly class DbalInventoryReadModel implements InventoryReadModel
{
    private const string NO_ID = '00000000-0000-0000-0000-000000000000';
    /** The name of a host: its display name (Fleet's label), else its hostname. */
    private const string HOST_NAME = "COALESCE(l.display_name, h.facts->>'hostname', '')";

    private const string PACKAGES_OF = '(SELECT COUNT(*) FROM inventory_package p JOIN inventory_blob b ON b.id = p.blob_id WHERE b.team_id = %s AND b.digest = %s)';

    public function __construct(private Connection $connection)
    {
    }

    public function applications(string $teamId, ?array $environmentIds, bool $unattachedOnly, ?string $hostId, ?string $imageId, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('inventory_app_instance', 'a')
            ->leftJoin('a', 'inventory_host', 'h', 'h.host_id = a.host_id')->leftJoin('a', 'inventory_host_label', 'l', 'l.host_id = a.host_id')
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

        $rows = $this->page($base, $page, static fn (QueryBuilder $q) => $q->andWhere('(LOWER(a.subject_key) LIKE :search OR LOWER('.self::HOST_NAME.') LIKE :search)'),
            ['name' => 'a.subject_key', 'host' => self::HOST_NAME, 'last_seen' => 'a.last_seen_at'], 'name',
            static fn (QueryBuilder $q) => $q->select(...self::appColumns()), 'a.id');

        return new Page(array_map(self::app(...), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function application(string $appId): ?AppRow
    {
        $row = $this->connection->createQueryBuilder()->select(...self::appColumns())->from('inventory_app_instance', 'a')
            ->leftJoin('a', 'inventory_host', 'h', 'h.host_id = a.host_id')->leftJoin('a', 'inventory_host_label', 'l', 'l.host_id = a.host_id')->where('a.id = :id')->setParameter('id', $appId)->fetchAssociative();

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

    public function packagesNamed(string $teamId, string $digest, array $names): array
    {
        if ([] === $names) {
            return [];
        }

        return array_map(static fn (array $row): PackageRow => new PackageRow(
            Row::string($row['purl']), Row::string($row['type']), Row::nullableString($row['namespace']), Row::string($row['name']),
            Row::nullableString($row['version']), Row::nullableString($row['dependency']), Row::nullableString($row['manager']),
        ), $this->connection->fetchAllAssociative(
            "SELECT p.purl, p.type, p.namespace, p.name, p.version, p.dependency, p.manager FROM inventory_package p JOIN inventory_blob b ON b.id = p.blob_id
             WHERE b.team_id = :team AND b.digest = :digest AND p.type || ':' || COALESCE(p.namespace || '/', '') || p.name IN (:names) ORDER BY p.purl",
            ['team' => $teamId, 'digest' => $digest, 'names' => $names],
            ['names' => ArrayParameterType::STRING],
        ));
    }

    public function distro(string $teamId, string $digest): ?string
    {
        $distro = $this->connection->fetchOne(
            "SELECT SUBSTRING(p.purl FROM '[?&]distro=([^&#]+)') FROM inventory_package p JOIN inventory_blob b ON b.id = p.blob_id
             WHERE b.team_id = :team AND b.digest = :digest AND p.type IN ('apk', 'deb', 'rpm') AND p.purl ~ '[?&]distro=[^&#]' ORDER BY p.purl LIMIT 1",
            ['team' => $teamId, 'digest' => $digest],
        );

        return \is_string($distro) ? rawurldecode($distro) : null;
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
            'SELECT h.host_id, h.team_id, h.facts, h.packages_digest, h.inventoried_at, l.display_name, l.addresses, '.\sprintf(self::PACKAGES_OF, 'h.team_id', 'h.packages_digest').' AS packages
             FROM inventory_host h LEFT JOIN inventory_host_label l ON l.host_id = h.host_id WHERE h.host_id = :id',
            ['id' => $hostId],
        );
        if (false === $row) {
            return null;
        }

        $facts = self::json($row['facts']);

        return new HostView(
            Row::string($row['host_id']), Row::string($row['team_id']), \is_string($facts['hostname'] ?? null) ? $facts['hostname'] : '', $facts,
            Row::nullableString($row['packages_digest']), null === Row::nullableString($row['inventoried_at']) ? null : new \DateTimeImmutable(Row::string($row['inventoried_at'])), Row::int($row['packages']),
            Row::nullableString($row['display_name']), array_values(array_filter(self::json($row['addresses']), \is_string(...))),
        );
    }

    public function matrixEntries(string $teamId, string $environmentId): array
    {
        $parameters = ['team' => $teamId, 'environment' => $environmentId];
        $apps = 'FROM inventory_app_instance a LEFT JOIN inventory_host h ON h.host_id = a.host_id LEFT JOIN inventory_host_label l ON l.host_id = a.host_id
            WHERE a.team_id = :team AND a.environment_id = :environment AND a.present';
        $entries = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT a.id, a.fields, a.subject_key, a.host_id, a.image_id, '.self::HOST_NAME." AS hostname, h.facts->'os'->>'id' AS os_id, h.facts->'os'->>'version_id' AS os_version,
                (SELECT SUBSTRING(p.purl FROM '[?&]distro=([^&#]+)') FROM inventory_container_image i JOIN inventory_blob b ON b.team_id = i.team_id AND b.digest = i.sbom_digest
                    JOIN inventory_package p ON p.blob_id = b.id
                    WHERE i.team_id = a.team_id AND i.image_id = a.image_id AND p.type IN ('apk', 'deb', 'rpm') AND p.purl ~ '[?&]distro=[^&#]' ORDER BY p.purl LIMIT 1) AS distro
             $apps ORDER BY a.id",
            $parameters,
        ) as $row) {
            $fields = self::json($row['fields']);
            $place = [Row::string($row['id']), AppRow::nameOf($fields, Row::string($row['subject_key'])), Row::string($row['host_id']), Row::string($row['hostname'])];
            $runtime = \is_array($fields['runtime'] ?? null) ? $fields['runtime'] : [];
            if (\is_string($runtime['name'] ?? null) && \is_string($runtime['version'] ?? null)) {
                $entries[] = new MatrixEntry(MatrixRow::RUNTIME, $runtime['name'], $runtime['name'], $runtime['version'], ...$place);
            }

            // The OS of its image; an application without image runs on the OS of its host.
            $distro = Row::nullableString($row['distro']);
            $match = null === $distro ? [] : u(rawurldecode($distro))->match('/^(.+?)-(\d[^-]*)$/');
            $osId = null === Row::nullableString($row['image_id']) ? Row::nullableString($row['os_id']) : ($match[1] ?? null);
            $osVersion = null === Row::nullableString($row['image_id']) ? Row::nullableString($row['os_version']) : ($match[2] ?? null);
            if (\is_string($osId) && \is_string($osVersion)) {
                $entries[] = new MatrixEntry(MatrixRow::OS, $osId, OsName::of($osId), $osVersion, ...$place);
            }
        }

        foreach ($this->connection->fetchAllAssociative(
            'SELECT a.id, a.fields, a.subject_key, a.host_id, '.self::HOST_NAME." AS hostname, p.type, p.namespace, p.name, p.version
             FROM inventory_app_instance a JOIN inventory_blob b ON b.team_id = a.team_id AND b.digest = a.sbom_digest JOIN inventory_package p ON p.blob_id = b.id
             LEFT JOIN inventory_host h ON h.host_id = a.host_id LEFT JOIN inventory_host_label l ON l.host_id = a.host_id
             WHERE a.team_id = :team AND a.environment_id = :environment AND a.present AND p.dependency = 'direct' AND p.version IS NOT NULL ORDER BY a.id, p.purl",
            $parameters,
        ) as $row) {
            $namespace = Row::nullableString($row['namespace']);
            $entries[] = new MatrixEntry(
                MatrixRow::PACKAGE, Row::string($row['type']), (null === $namespace ? '' : $namespace.'/').Row::string($row['name']), Row::string($row['version']),
                Row::string($row['id']), AppRow::nameOf(self::json($row['fields']), Row::string($row['subject_key'])), Row::string($row['host_id']), Row::string($row['hostname']),
            );
        }

        return $entries;
    }

    public function hostLabel(string $hostId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT l.display_name, l.addresses FROM inventory_host_label l WHERE l.host_id = :id', ['id' => $hostId]);

        return false === $row ? null : ['displayName' => Row::nullableString($row['display_name']), 'addresses' => array_values(array_filter(self::json($row['addresses']), \is_string(...)))];
    }

    public function teamPackages(string $teamId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.purl, bool_or(p.dependency = 'direct') AS direct FROM inventory_package p JOIN inventory_blob b ON b.id = p.blob_id
             WHERE b.team_id = :team AND b.digest IN (
                SELECT h.packages_digest FROM inventory_host h WHERE h.team_id = :team AND h.packages_digest IS NOT NULL
                UNION SELECT a.sbom_digest FROM inventory_app_instance a WHERE a.team_id = :team AND a.present
                UNION SELECT i.sbom_digest FROM inventory_container_image i WHERE i.team_id = :team
             ) GROUP BY p.purl ORDER BY p.purl",
            ['team' => $teamId],
        );

        return array_map(static fn (array $row): array => ['purl' => Row::string($row['purl']), 'direct' => true === $row['direct']], $rows);
    }

    public function teamSubjects(string $teamId): array
    {
        $apps = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.fields, a.subject_key, a.environment_id, a.sbom_digest, i.sbom_digest AS image_digest, a.host_id, '.self::HOST_NAME.' AS hostname
             FROM inventory_app_instance a
             LEFT JOIN inventory_container_image i ON i.team_id = a.team_id AND i.image_id = a.image_id
             LEFT JOIN inventory_host h ON h.host_id = a.host_id LEFT JOIN inventory_host_label l ON l.host_id = a.host_id
             WHERE a.team_id = :team AND a.present ORDER BY a.id',
            ['team' => $teamId],
        );
        $hosts = $this->connection->fetchAllAssociative(
            'SELECT h.host_id, h.facts, h.packages_digest, l.display_name FROM inventory_host h LEFT JOIN inventory_host_label l ON l.host_id = h.host_id WHERE h.team_id = :team ORDER BY h.host_id',
            ['team' => $teamId],
        );
        $digests = array_values(array_unique(array_filter([
            ...array_map(static fn (array $a): ?string => Row::nullableString($a['sbom_digest']), $apps),
            ...array_map(static fn (array $a): ?string => Row::nullableString($a['image_digest']), $apps),
            ...array_map(static fn (array $h): ?string => Row::nullableString($h['packages_digest']), $hosts),
        ])));
        $packages = [];
        foreach ([] === $digests ? [] : $this->connection->fetchAllAssociative(
            'SELECT b.digest, p.purl, p.dependency FROM inventory_package p JOIN inventory_blob b ON b.id = p.blob_id WHERE b.team_id = :team AND b.digest IN (:digests) ORDER BY p.purl',
            ['team' => $teamId, 'digests' => $digests],
            ['digests' => ArrayParameterType::STRING],
        ) as $row) {
            $packages[Row::string($row['digest'])][] = ['purl' => Row::string($row['purl']), 'direct' => 'direct' === Row::nullableString($row['dependency'])];
        }

        $subjects = [];
        foreach ($apps as $app) {
            $fields = self::json($app['fields']);
            $runtime = $fields['runtime'] ?? null;
            $runtimes = \is_array($runtime) && \is_string($runtime['name'] ?? null) && \is_string($runtime['version'] ?? null) ? [['name' => $runtime['name'], 'version' => $runtime['version']]] : [];
            $subjects[] = new TeamSubject(TeamSubject::APPLICATION, Row::string($app['id']), [...$packages[Row::string($app['sbom_digest'])] ?? [], ...$packages[Row::nullableString($app['image_digest']) ?? ''] ?? []], $runtimes, null,
                AppRow::nameOf($fields, Row::string($app['subject_key'])), Row::nullableString($app['environment_id']), Row::string($app['host_id']), Row::string($app['hostname']));
        }

        foreach ($hosts as $host) {
            $facts = self::json($host['facts']);
            $runtimes = [];
            foreach (\is_array($facts['runtimes'] ?? null) ? $facts['runtimes'] : [] as $runtime) {
                if (\is_array($runtime) && \is_string($runtime['name'] ?? null) && \is_string($runtime['version'] ?? null)) {
                    $runtimes[] = ['name' => $runtime['name'], 'version' => $runtime['version']];
                }
            }

            $os = \is_array($facts['os'] ?? null) ? $facts['os'] : [];
            $name = Row::nullableString($host['display_name']) ?? (\is_string($facts['hostname'] ?? null) ? $facts['hostname'] : '');
            $subjects[] = new TeamSubject(TeamSubject::HOST, Row::string($host['host_id']), $packages[Row::nullableString($host['packages_digest']) ?? ''] ?? [], $runtimes,
                \is_string($os['id'] ?? null) && \is_string($os['version_id'] ?? null) ? ['id' => $os['id'], 'version' => $os['version_id']] : null,
                $name, null, Row::string($host['host_id']), $name);
        }

        return $subjects;
    }

    public function inventoriedTeams(): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT DISTINCT team_id FROM inventory_host ORDER BY team_id');
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
        return ['a.id', 'a.team_id', 'a.host_id', self::HOST_NAME.' AS hostname', 'a.subject_key', 'a.kind', 'a.fields', 'a.image_id', 'a.environment_id', 'a.links',
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
            links: self::links($row['links']),
        );
    }

    /**
     * @return list<array{url: string, label: ?string}>
     */
    private static function links(mixed $value): array
    {
        $links = [];
        foreach (self::json($value) as $link) {
            if (\is_array($link) && \is_string($link['url'] ?? null)) {
                $links[] = ['url' => $link['url'], 'label' => \is_string($link['label'] ?? null) ? $link['label'] : null];
            }
        }

        return $links;
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
