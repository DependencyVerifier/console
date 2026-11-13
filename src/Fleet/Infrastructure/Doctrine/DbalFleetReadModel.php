<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Fleet\Application\Query\FleetReadModel;
use Fleet\Application\Query\View\AgentRow;
use Fleet\Application\Query\View\HostRow;
use Fleet\Application\Query\View\OrderRow;
use Fleet\Application\Query\View\TokenRow;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Shared\Infrastructure\Doctrine\Row;

use function Symfony\Component\String\u;

final readonly class DbalFleetReadModel implements FleetReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    public function agents(?string $teamId, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('fleet_agent', 'a')->innerJoin('a', 'fleet_host', 'h', 'h.id = a.host_id');
        if (null !== $teamId) {
            $base->where('a.team_id = :team')->setParameter('team', $teamId);
        }

        $rows = $this->page(
            $base, $page,
            static fn (QueryBuilder $q) => $q->andWhere('(LOWER(COALESCE(h.display_name, h.hostname)) LIKE :search OR LOWER(h.hostname) LIKE :search OR LOWER(a.fingerprint) LIKE :search OR a.status LIKE :search OR LOWER(COALESCE(a.compose_project, \'\')) LIKE :search)'),
            ['host' => 'COALESCE(h.display_name, h.hostname)', 'status' => 'a.status', 'version' => 'a.version', 'last_seen' => 'a.last_seen_at'],
            'host',
            static fn (QueryBuilder $q) => $q->select('a.id', 'a.team_id', 'a.status', 'a.fingerprint', 'a.mode', 'a.scope', 'a.compose_project', 'a.version', 'a.host_id', 'COALESCE(h.display_name, h.hostname) AS hostname', 'a.enrolled_at', 'a.last_seen_at'),
            'a.id',
        );

        return new Page(array_map(static fn (array $row): AgentRow => new AgentRow(
            Row::string($row['id']), Row::string($row['team_id']), Row::string($row['status']), Row::string($row['fingerprint']), Row::string($row['mode']),
            Row::nullableString($row['scope']), Row::nullableString($row['compose_project']), Row::string($row['version']), Row::string($row['host_id']),
            Row::string($row['hostname']), new \DateTimeImmutable(Row::string($row['enrolled_at'])), new \DateTimeImmutable(Row::string($row['last_seen_at'])),
        ), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function hosts(string $teamId, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('fleet_host', 'h')->where('h.team_id = :team')->setParameter('team', $teamId);
        $rows = $this->page(
            $base, $page,
            static fn (QueryBuilder $q) => $q->andWhere('(LOWER(COALESCE(h.display_name, h.hostname)) LIKE :search OR LOWER(h.hostname) LIKE :search OR LOWER(COALESCE(h.os_pretty_name, \'\')) LIKE :search)'),
            ['hostname' => 'COALESCE(h.display_name, h.hostname)', 'last_seen' => 'h.last_seen_at'],
            'hostname',
            static fn (QueryBuilder $q) => $q->select(
                'h.id', 'COALESCE(h.display_name, h.hostname) AS hostname', 'h.os_pretty_name', 'h.arch', 'h.last_seen_at', 'h.created_at',
                "(SELECT COUNT(*) FROM fleet_agent a WHERE a.host_id = h.id AND a.status <> 'revoked') AS live_agents",
                '(SELECT COUNT(*) FROM fleet_agent a WHERE a.host_id = h.id) AS agents',
            ),
            'h.id',
        );

        return new Page(array_map(static fn (array $row): HostRow => new HostRow(
            Row::string($row['id']), Row::string($row['hostname']), Row::nullableString($row['os_pretty_name']), Row::nullableString($row['arch']),
            Row::int($row['live_agents']), Row::int($row['agents']),
            null === Row::nullableString($row['last_seen_at']) ? null : new \DateTimeImmutable(Row::string($row['last_seen_at'])), new \DateTimeImmutable(Row::string($row['created_at'])),
        ), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function orders(string $agentId, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('fleet_agent_order', 'o')->where('o.agent_id = :agent')->setParameter('agent', $agentId);
        $rows = $this->page(
            $base, $page,
            static fn (QueryBuilder $q) => $q->andWhere('(LOWER(o.type) LIKE :search OR o.status LIKE :search OR LOWER(COALESCE(o.reason, \'\')) LIKE :search)'),
            ['created' => 'o.created_at'],
            'created',
            static fn (QueryBuilder $q) => $q->select('o.id', 'o.type', 'o.status', 'o.created_at', 'o.issued_at', 'o.finished_at', 'o.reason'),
            'o.id',
            descendingByDefault: true,
        );
        $time = static fn (mixed $value): ?\DateTimeImmutable => null === Row::nullableString($value) ? null : new \DateTimeImmutable(Row::string($value));

        return new Page(array_map(static fn (array $row): OrderRow => new OrderRow(
            Row::string($row['id']), Row::string($row['type']), Row::string($row['status']), new \DateTimeImmutable(Row::string($row['created_at'])),
            $time($row['issued_at']), $time($row['finished_at']), Row::nullableString($row['reason']),
        ), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function tokens(string $teamId, PageRequest $page, \DateTimeImmutable $now): Page
    {
        $base = $this->connection->createQueryBuilder()->from('fleet_enrollment_token', 't')->where('t.team_id = :team')->setParameter('team', $teamId);
        $rows = $this->page(
            $base, $page,
            static fn (QueryBuilder $q) => $q->andWhere('(t.kind LIKE :search OR t.status LIKE :search)'),
            ['created' => 't.created_at', 'expires' => 't.expires_at'],
            'created',
            static fn (QueryBuilder $q) => $q->select('t.id', 't.kind', 't.status', 't.environment_id', 't.uses', 't.max_uses', 't.expires_at', 't.created_at'),
            't.id',
            descendingByDefault: true,
        );

        return new Page(array_map(static function (array $row) use ($now): TokenRow {
            $expiresAt = new \DateTimeImmutable(Row::string($row['expires_at']));
            $status = Row::string($row['status']);

            return new TokenRow(
                Row::string($row['id']), Row::string($row['kind']), $status, Row::nullableString($row['environment_id']), Row::int($row['uses']), Row::int($row['max_uses']),
                $expiresAt, new \DateTimeImmutable(Row::string($row['created_at'])), 'revoked' !== $status && $now < $expiresAt && Row::int($row['uses']) < Row::int($row['max_uses']),
            );
        }, $rows['items']), $rows['filtered'], $rows['total']);
    }

    /**
     * @param \Closure(QueryBuilder): mixed $search     a condition using `:search`
     * @param array<string, string>         $sortable   key => column
     * @param \Closure(QueryBuilder): mixed $select
     * @param string                        $tieBreaker a unique column (UUID v7 ids grow with time): rows created in
     *                                                  the same second keep a stable order
     *
     * @return array{items: list<array<string, mixed>>, filtered: int, total: int}
     */
    private function page(QueryBuilder $base, PageRequest $page, \Closure $search, array $sortable, string $defaultSort, \Closure $select, string $tieBreaker, bool $descendingByDefault = false): array
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
        $descending = null === $page->sort ? $descendingByDefault : $page->descending;
        $items->orderBy($sortable[$page->sortAmong(array_keys($sortable), $defaultSort)], $descending ? 'DESC' : 'ASC')
            ->addOrderBy($tieBreaker, $descending ? 'DESC' : 'ASC')
            ->setFirstResult($page->offset)->setMaxResults($page->limit);

        /** @var list<array<string, mixed>> $rows */
        $rows = $items->fetchAllAssociative();

        return ['items' => $rows, 'filtered' => $count, 'total' => $total];
    }
}
