<?php

declare(strict_types=1);

namespace Exposure\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Exposure\Application\Query\ExposureFilter;
use Exposure\Application\Query\FindingCriteria;
use Exposure\Application\Query\FindingReadModel;
use Exposure\Application\Query\View\ExposureSummary;
use Exposure\Application\Query\View\FindingRow;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Shared\Infrastructure\Doctrine\Row;

use function Symfony\Component\String\u;

final readonly class DbalFindingReadModel implements FindingReadModel
{
    private const string ACCEPTED_UNTIL = '(SELECT MAX(r.expires_on) FROM exposure_risk_acceptance r WHERE r.finding_id = f.id AND r.revoked_at IS NULL AND r.expires_on > :today) AS accepted_until';
    private const string NO_ID = '00000000-0000-0000-0000-000000000000';
    private const string ACCEPTED = '(EXISTS (SELECT 1 FROM exposure_risk_acceptance a WHERE a.finding_id = f.id AND a.revoked_at IS NULL AND a.expires_on > :today))';
    private const string ORDER = "CASE f.status WHEN 'open' THEN 0 ELSE 1 END, CASE f.kind WHEN 'vulnerability' THEN 0 WHEN 'eol' THEN 1 ELSE 2 END,
        CASE f.severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END, f.known_exploited DESC, f.end_of_life NULLS LAST, f.title, f.id";

    public function __construct(private Connection $connection)
    {
    }

    public function ofSubject(string $teamId, string $subjectType, string $subjectId, \DateTimeImmutable $today, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('exposure_finding', 'f')
            ->where('f.team_id = :team')->andWhere('f.subject_type = :type')->andWhere('f.subject_id = :subject')
            ->setParameter('team', $teamId)->setParameter('type', $subjectType)->setParameter('subject', $subjectId);

        return $this->paged($base, $today, $page);
    }

    public function list(ExposureFilter $filter, FindingCriteria $criteria, \DateTimeImmutable $today, PageRequest $page): Page
    {
        $base = $this->scoped($filter, $today);
        if (null !== $criteria->kind) {
            $base->andWhere('f.kind = :kind')->setParameter('kind', $criteria->kind);
        }

        if (null !== $criteria->severity) {
            $base->andWhere('f.severity = :severity')->setParameter('severity', $criteria->severity);
        }

        if (null !== $criteria->projectId) {
            $base->andWhere('f.project_id = :criteria_project')->setParameter('criteria_project', $criteria->projectId);
        }

        if (null !== $criteria->environmentId) {
            $base->andWhere('f.environment_id = :criteria_environment')->setParameter('criteria_environment', $criteria->environmentId);
        }

        match ($criteria->status) {
            'open' => $base->andWhere("f.status = 'open'")->andWhere('NOT '.self::ACCEPTED),
            'accepted' => $base->andWhere("f.status = 'open'")->andWhere(self::ACCEPTED),
            'fixed' => $base->andWhere("f.status = 'fixed'"),
            default => $base,
        };

        return $this->paged($base, $today, $page);
    }

    public function summary(ExposureFilter $filter, \DateTimeImmutable $today): ExposureSummary
    {
        $open = "f.status = 'open' AND NOT ".self::ACCEPTED;
        $counts = $this->scoped($filter, $today)->select(
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'vulnerability' AND f.severity = 'critical') AS critical",
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'vulnerability' AND f.severity = 'high') AS high",
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'vulnerability' AND f.severity = 'medium') AS medium",
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'vulnerability' AND f.severity = 'low') AS low",
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'vulnerability' AND (f.severity IS NULL OR f.severity NOT IN ('critical', 'high', 'medium', 'low'))) AS unknown",
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'vulnerability' AND f.known_exploited) AS known_exploited",
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'eol' AND f.eol_level = 'upcoming') AS upcoming",
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'eol' AND f.eol_level = 'soon') AS soon",
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'eol' AND f.eol_level = 'imminent') AS imminent",
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'eol' AND f.eol_level = 'expired') AS expired",
            "COUNT(*) FILTER (WHERE $open AND f.kind = 'outdated') AS outdated",
            "COUNT(*) FILTER (WHERE f.status = 'open' AND ".self::ACCEPTED.') AS accepted',
        )->fetchAssociative() ?: [];
        $int = static fn (string $key): int => Row::int($counts[$key] ?? 0);

        $first = $today->setTime(0, 0)->modify('-29 days');
        $trend = [];
        for ($day = $first; $day <= $today; $day = $day->modify('+1 day')) {
            $trend[$day->format('Y-m-d')] = ['day' => $day->format('Y-m-d'), 'opened' => 0, 'fixed' => 0];
        }

        foreach (['opened' => 'f.first_seen_at', 'fixed' => 'f.fixed_at'] as $series => $column) {
            $rows = $this->scoped($filter, $today)->select("CAST($column AS DATE) AS day", 'COUNT(*) AS n')->andWhere("$column >= :first")->setParameter('first', $first->format('Y-m-d'))
                ->groupBy('day')->fetchAllAssociative();
            foreach ($rows as $row) {
                $day = Row::string($row['day']);
                if (isset($trend[$day])) {
                    $trend[$day][$series] = Row::int($row['n']);
                }
            }
        }

        $top = $this->scoped($filter, $today)->select('f.subject_id', 'MAX(f.subject_name) AS name',
            "COUNT(*) FILTER (WHERE f.kind = 'vulnerability' AND f.known_exploited) AS known_exploited",
            "COUNT(*) FILTER (WHERE f.kind = 'vulnerability' AND f.severity = 'critical') AS critical",
            "COUNT(*) FILTER (WHERE f.kind = 'vulnerability' AND f.severity = 'high') AS high", 'COUNT(*) AS total')
            ->andWhere("f.subject_type = 'application'")->andWhere($open)
            ->groupBy('f.subject_id')->orderBy('known_exploited', 'DESC')->addOrderBy('critical', 'DESC')->addOrderBy('high', 'DESC')->addOrderBy('total', 'DESC')->addOrderBy('name')
            ->setMaxResults(5)->fetchAllAssociative();

        $calendar = $this->scoped($filter, $today)->select('f.title', 'f.package', 'f.end_of_life', 'MAX(f.eol_level) AS level', 'MAX(f.suggested) AS suggested', 'COUNT(DISTINCT f.subject_id) AS subjects')
            ->andWhere("f.kind = 'eol'")->andWhere("f.status = 'open'")
            ->groupBy('f.title', 'f.package', 'f.end_of_life')->orderBy('f.end_of_life')->addOrderBy('f.title')->fetchAllAssociative();

        return new ExposureSummary(
            ['critical' => $int('critical'), 'high' => $int('high'), 'medium' => $int('medium'), 'low' => $int('low'), 'unknown' => $int('unknown')],
            $int('known_exploited'),
            ['upcoming' => $int('upcoming'), 'soon' => $int('soon'), 'imminent' => $int('imminent'), 'expired' => $int('expired')],
            $int('outdated'),
            $int('accepted'),
            array_values($trend),
            array_values(array_map(static fn (array $row): array => ['id' => Row::string($row['subject_id']), 'name' => Row::string($row['name']), 'knownExploited' => Row::int($row['known_exploited']),
                'critical' => Row::int($row['critical']), 'high' => Row::int($row['high']), 'total' => Row::int($row['total'])], $top)),
            array_values(array_map(static fn (array $row): array => ['title' => Row::string($row['title']), 'product' => Row::string($row['package']), 'endOfLife' => Row::nullableString($row['end_of_life']),
                'level' => Row::nullableString($row['level']), 'suggested' => Row::nullableString($row['suggested']), 'subjects' => Row::int($row['subjects'])], $calendar)),
        );
    }

    /**
     * @return Page<FindingRow>
     */
    private function paged(QueryBuilder $base, \DateTimeImmutable $today, PageRequest $page): Page
    {
        $base->setParameter('today', $today->format('Y-m-d'));
        $total = Row::int((clone $base)->select('COUNT(*)')->fetchOne());
        $filtered = clone $base;
        if ('' !== $page->search) {
            $filtered->andWhere('(LOWER(f.title) LIKE :search OR LOWER(f.package) LIKE :search OR LOWER(f.finding_key) LIKE :search OR LOWER(f.subject_name) LIKE :search OR LOWER(CAST(f.aliases AS TEXT)) LIKE :search)')
                ->setParameter('search', '%'.u($page->search)->lower()->replace('\\', '\\\\')->replace('%', '\\%')->replace('_', '\\_')->toString().'%');
        }

        $count = Row::int((clone $filtered)->select('COUNT(*)')->fetchOne());
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select($filtered)->orderBy(self::ORDER)->setFirstResult($page->offset)->setMaxResults($page->limit)->fetchAllAssociative();

        return new Page(array_map(self::row(...), $rows), $count, $total);
    }

    /**
     * The findings of the team the filter lets through.
     */
    private function scoped(ExposureFilter $filter, \DateTimeImmutable $today): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()->from('exposure_finding', 'f')->where('f.team_id = :team')
            ->setParameter('team', $filter->teamId)->setParameter('today', $today->format('Y-m-d'));
        if (null !== $filter->projectIds) {
            $query->andWhere('f.project_id IN (:projects)')->setParameter('projects', [] === $filter->projectIds ? [self::NO_ID] : $filter->projectIds, ArrayParameterType::STRING);
        }

        if (null !== $filter->projectId) {
            $query->andWhere('f.project_id = :project')->setParameter('project', $filter->projectId);
        }

        if (null !== $filter->environmentId) {
            $query->andWhere('f.environment_id = :environment')->setParameter('environment', $filter->environmentId);
        }

        if (null !== $filter->hostId) {
            $query->andWhere("f.subject_type = 'host'")->andWhere('f.subject_id = :host')->setParameter('host', $filter->hostId);
        }

        return $query;
    }

    public function find(string $findingId, \DateTimeImmutable $today): ?FindingRow
    {
        $row = $this->select($this->connection->createQueryBuilder()->from('exposure_finding', 'f')->where('f.id = :id')->setParameter('id', $findingId))
            ->setParameter('today', $today->format('Y-m-d'))->fetchAssociative();

        return false === $row ? null : self::row($row);
    }

    private function select(QueryBuilder $query): QueryBuilder
    {
        return $query->select('f.id', 'f.team_id', 'f.subject_type', 'f.subject_id', 'f.kind', 'f.title', 'f.package', 'f.installed', 'f.fixed', 'f.severity', 'f.cvss', 'f.known_exploited', 'f.epss',
            'f.eol_level', 'f.end_of_life', 'f.latest', 'f.aliases', 'f.status', 'f.first_seen_at', 'f.last_seen_at', 'f.fixed_at', self::ACCEPTED_UNTIL, 'f.subject_name', 'f.suggested', 'f.project_id', 'f.environment_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): FindingRow
    {
        $date = static fn (mixed $value): ?\DateTimeImmutable => null === Row::nullableString($value) ? null : new \DateTimeImmutable(Row::string($value));
        $aliases = json_decode(Row::string($row['aliases']), true);

        return new FindingRow(
            Row::string($row['id']), Row::string($row['team_id']), Row::string($row['subject_type']), Row::string($row['subject_id']), Row::string($row['kind']),
            Row::string($row['title']), Row::string($row['package']), Row::nullableString($row['installed']), Row::nullableString($row['fixed']), Row::nullableString($row['severity']),
            is_numeric($row['cvss']) ? (float) $row['cvss'] : null, true === $row['known_exploited'], is_numeric($row['epss']) ? (float) $row['epss'] : null,
            Row::nullableString($row['eol_level']), $date($row['end_of_life']), Row::nullableString($row['latest']),
            \is_array($aliases) ? array_values(array_filter($aliases, \is_string(...))) : [], Row::string($row['status']),
            new \DateTimeImmutable(Row::string($row['first_seen_at'])), new \DateTimeImmutable(Row::string($row['last_seen_at'])), $date($row['fixed_at']), $date($row['accepted_until']),
            Row::string($row['subject_name']), Row::nullableString($row['suggested']), Row::nullableString($row['project_id']), Row::nullableString($row['environment_id']),
        );
    }
}
