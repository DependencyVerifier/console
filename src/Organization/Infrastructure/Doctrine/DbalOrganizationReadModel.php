<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\View\EnvironmentRow;
use Organization\Application\Query\View\InvitationRow;
use Organization\Application\Query\View\MemberRow;
use Organization\Application\Query\View\ProjectRow;
use Organization\Application\Query\View\TeamSummary;
use Shared\Application\Contract\Organization\PendingInvitation;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Shared\Infrastructure\Doctrine\Row;

use function Symfony\Component\String\u;

/**
 * DBAL read side. Sort keys are whitelisted per list, search terms bound as parameters, never concatenated.
 */
final readonly class DbalOrganizationReadModel implements OrganizationReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    public function teams(?array $teamIds, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('organization_team', 't');
        if (null !== $teamIds) {
            $base->andWhere('t.id IN (:ids)')->setParameter('ids', $teamIds ?: ['00000000-0000-0000-0000-000000000000'], ArrayParameterType::STRING);
        }

        $rows = $this->page($base, $page, 't.name', ['name' => 't.name', 'created_at' => 't.created_at'], 'name', static fn (QueryBuilder $q) => $q->select(
            't.id', 't.name', 't.slug', 't.created_at',
            "(SELECT COUNT(*) FROM organization_team_membership m WHERE m.team_id = t.id AND m.status = 'active') AS members",
            '(SELECT COUNT(*) FROM organization_project p WHERE p.team_id = t.id) AS projects',
        ));

        return new Page(array_map(static fn (array $row): TeamSummary => new TeamSummary(
            Row::string($row['id']), Row::string($row['name']), Row::string($row['slug']), null, Row::int($row['members']), Row::int($row['projects']), new \DateTimeImmutable(Row::string($row['created_at'])),
        ), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function team(string $teamId): ?TeamSummary
    {
        $page = $this->teams([$teamId], new PageRequest(0, 1));

        return $page->items[0] ?? null;
    }

    public function activeMembers(string $teamId): int
    {
        return Row::int($this->connection->fetchOne("SELECT COUNT(*) FROM organization_team_membership WHERE team_id = :team AND status = 'active'", ['team' => $teamId]));
    }

    public function pendingInvitations(string $teamId, \DateTimeImmutable $now): int
    {
        return Row::int($this->connection->fetchOne(
            "SELECT COUNT(*) FROM organization_invitation WHERE team_id = :team AND status = 'pending' AND issued_at > :since",
            ['team' => $teamId, 'since' => $now->modify('-7 days')->format('Y-m-d H:i:s')],
        ));
    }

    public function projectCount(string $teamId): int
    {
        return Row::int($this->connection->fetchOne('SELECT COUNT(*) FROM organization_project WHERE team_id = :team', ['team' => $teamId]));
    }

    public function teamMembers(string $teamId, PageRequest $page, ?array $matchingUserIds = null): Page
    {
        $base = $this->connection->createQueryBuilder()->from('organization_team_membership', 'm')
            ->where('m.team_id = :team')->setParameter('team', $teamId);
        $rows = $this->page($base, $page, self::memberSearch($matchingUserIds), ['role' => 'm.role', 'joined_at' => 'm.joined_at'], 'joined_at', static fn (QueryBuilder $q) => $q->select(
            'm.user_id', 'm.role', 'm.extra_permissions', 'm.status', 'm.joined_at',
            '(SELECT COUNT(*) FROM organization_project_membership pm JOIN organization_project p ON p.id = pm.project_id WHERE p.team_id = m.team_id AND pm.user_id = m.user_id) AS projects',
        ));

        return new Page(array_map(self::member(...), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function projectMembers(string $projectId, PageRequest $page, ?array $matchingUserIds = null): Page
    {
        $base = $this->connection->createQueryBuilder()->from('organization_project_membership', 'm')
            ->where('m.project_id = :project')->setParameter('project', $projectId);
        $rows = $this->page($base, $page, self::memberSearch($matchingUserIds), ['role' => 'm.role', 'joined_at' => 'm.joined_at'], 'joined_at', static fn (QueryBuilder $q) => $q->select(
            'm.user_id', 'm.role', 'm.extra_permissions', 'm.status', 'm.joined_at', '1 AS projects',
        ));

        return new Page(array_map(self::member(...), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function invitations(?string $teamId, ?string $projectId, PageRequest $page, \DateTimeImmutable $now): Page
    {
        $base = $this->connection->createQueryBuilder()->from('organization_invitation', 'i')
            ->innerJoin('i', 'organization_team', 't', 't.id = i.team_id')
            ->leftJoin('i', 'organization_project', 'p', 'p.id = i.project_id')
            ->where("i.status = 'pending'");
        if (null !== $teamId) {
            $base->andWhere('i.team_id = :team')->setParameter('team', $teamId);
        }

        if (null !== $projectId) {
            $base->andWhere('i.project_id = :project')->setParameter('project', $projectId);
        }

        $rows = $this->page($base, $page, 'i.email', ['email' => 'i.email', 'issued_at' => 'i.issued_at'], 'issued_at', static fn (QueryBuilder $q) => $q->select(
            'i.id', 'i.email', 'i.role', 'i.team_id', 't.name AS team_name', 'p.name AS project_name', 'i.issued_at',
        ));

        return new Page(array_map(static function (array $row) use ($now): InvitationRow {
            $expiresAt = new \DateTimeImmutable(Row::string($row['issued_at']))->modify('+7 days');

            return new InvitationRow(
                Row::string($row['id']), Row::string($row['email']), Row::string($row['role']), Row::nullableString($row['project_name']), $now >= $expiresAt, $expiresAt,
                Row::string($row['team_id']), Row::string($row['team_name']),
            );
        }, $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function projects(string $teamId, ?array $projectIds, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('organization_project', 'p')
            ->where('p.team_id = :team')->setParameter('team', $teamId);
        if (null !== $projectIds) {
            $base->andWhere('p.id IN (:ids)')->setParameter('ids', $projectIds ?: ['00000000-0000-0000-0000-000000000000'], ArrayParameterType::STRING);
        }

        $rows = $this->page($base, $page, 'p.name', ['name' => 'p.name', 'created_at' => 'p.created_at'], 'name', static fn (QueryBuilder $q) => $q->select(
            'p.id', 'p.name', 'p.slug', 'p.created_at',
            '(SELECT COUNT(*) FROM organization_environment e WHERE e.project_id = p.id) AS environments',
            '(SELECT COUNT(*) FROM organization_project_membership m WHERE m.project_id = p.id) AS members',
        ));

        return new Page(array_map(static fn (array $row): ProjectRow => new ProjectRow(
            Row::string($row['id']), Row::string($row['name']), Row::string($row['slug']), Row::int($row['environments']), Row::int($row['members']), new \DateTimeImmutable(Row::string($row['created_at'])),
        ), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function environments(string $projectId, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('organization_environment', 'e')
            ->where('e.project_id = :project')->setParameter('project', $projectId);
        $rows = $this->page($base, $page, 'e.name', ['name' => 'e.name', 'production' => 'e.production'], 'name', static fn (QueryBuilder $q) => $q->select('e.id', 'e.name', 'e.production'));

        return new Page(array_map(static fn (array $row): EnvironmentRow => new EnvironmentRow(Row::string($row['id']), Row::string($row['name']), (bool) $row['production']), $rows['items']), $rows['filtered'], $rows['total']);
    }

    public function teamEnvironments(string $teamId, ?string $environmentId = null): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('e.id', 'e.name', 'p.id AS project_id', 'p.name AS project_name')
            ->from('organization_environment', 'e')
            ->innerJoin('e', 'organization_project', 'p', 'p.id = e.project_id')
            ->where('p.team_id = :team')->setParameter('team', $teamId)
            ->orderBy('p.name')->addOrderBy('e.name');
        if (null !== $environmentId) {
            $query->andWhere('e.id = :environment')->setParameter('environment', $environmentId);
        }

        return array_values(array_map(static fn (array $row): TeamEnvironment => new TeamEnvironment(
            Row::string($row['id']), Row::string($row['name']), Row::string($row['project_id']), Row::string($row['project_name']),
        ), $query->fetchAllAssociative()));
    }

    public function pendingInvitation(?string $tokenHash, ?string $invitationId, \DateTimeImmutable $now): ?PendingInvitation
    {
        $query = $this->connection->createQueryBuilder()
            ->select('i.id', 'i.team_id', 'i.email', 'i.role', 'i.issued_at', 't.name AS team_name', 'p.name AS project_name')
            ->from('organization_invitation', 'i')
            ->innerJoin('i', 'organization_team', 't', 't.id = i.team_id')
            ->leftJoin('i', 'organization_project', 'p', 'p.id = i.project_id')
            ->where("i.status = 'pending'")->andWhere('i.issued_at > :since')
            ->setParameter('since', $now->modify('-7 days')->format('Y-m-d H:i:s'));
        null !== $tokenHash
            ? $query->andWhere('i.token_hash = :hash')->setParameter('hash', $tokenHash)
            : $query->andWhere('i.id = :id')->setParameter('id', $invitationId ?? '00000000-0000-0000-0000-000000000000');

        $row = $query->fetchAssociative();
        if (false === $row) {
            return null;
        }

        return new PendingInvitation(
            Row::string($row['id']), Row::string($row['team_id']), Row::string($row['email']), Row::string($row['team_name']), Row::nullableString($row['project_name']),
            Row::string($row['role']), new \DateTimeImmutable(Row::string($row['issued_at']))->modify('+7 days'),
        );
    }

    /**
     * @param string|\Closure(QueryBuilder): void $search   the searched column, or a condition using `:search`
     * @param array<string, string>               $sortable key => column
     * @param \Closure(QueryBuilder): mixed       $select
     *
     * @return array{items: list<array<string, mixed>>, filtered: int, total: int}
     */
    private function page(QueryBuilder $base, PageRequest $page, string|\Closure $search, array $sortable, string $defaultSort, \Closure $select): array
    {
        $total = Row::int((clone $base)->select('COUNT(*)')->fetchOne());
        $filtered = clone $base;
        if ('' !== $page->search) {
            $filtered->setParameter('search', '%'.u($page->search)->lower()->replace('\\', '\\\\')->replace('%', '\\%')->replace('_', '\\_')->toString().'%');
            \is_string($search) ? $filtered->andWhere(\sprintf('LOWER(%s) LIKE :search', $search)) : $search($filtered);
        }

        $count = Row::int((clone $filtered)->select('COUNT(*)')->fetchOne());
        $items = clone $filtered;
        $select($items);
        $items->orderBy($sortable[$page->sortAmong(array_keys($sortable), $defaultSort)], $page->descending ? 'DESC' : 'ASC')
            ->setFirstResult($page->offset)->setMaxResults($page->limit);

        /** @var list<array<string, mixed>> $rows */
        $rows = $items->fetchAllAssociative();

        return ['items' => $rows, 'filtered' => $count, 'total' => $total];
    }

    /**
     * A member is found by its role, or by its email or display name (the ids Identity matched for the term).
     *
     * @param list<string>|null $matchingUserIds
     *
     * @return \Closure(QueryBuilder): void
     */
    private static function memberSearch(?array $matchingUserIds): \Closure
    {
        return static function (QueryBuilder $query) use ($matchingUserIds): void {
            if ([] === ($matchingUserIds ?? [])) {
                $query->andWhere('LOWER(m.role) LIKE :search');

                return;
            }

            $query->andWhere('(LOWER(m.role) LIKE :search OR m.user_id IN (:matching))')->setParameter('matching', $matchingUserIds, ArrayParameterType::STRING);
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function member(array $row): MemberRow
    {
        $extras = json_decode(Row::string($row['extra_permissions']), true, 4, \JSON_THROW_ON_ERROR);

        return new MemberRow(
            Row::string($row['user_id']), '', Row::string($row['role']), \is_array($extras) ? array_values(array_filter($extras, is_string(...))) : [],
            'active' === $row['status'], Row::int($row['projects']), new \DateTimeImmutable(Row::string($row['joined_at'])),
        );
    }
}
