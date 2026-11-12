<?php

declare(strict_types=1);

namespace Exposure\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Exposure\Application\Query\FindingReadModel;
use Exposure\Application\Query\View\FindingRow;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Shared\Infrastructure\Doctrine\Row;

use function Symfony\Component\String\u;

final readonly class DbalFindingReadModel implements FindingReadModel
{
    private const string ACCEPTED_UNTIL = '(SELECT MAX(r.expires_on) FROM exposure_risk_acceptance r WHERE r.finding_id = f.id AND r.revoked_at IS NULL AND r.expires_on > :today) AS accepted_until';
    private const string ORDER = "CASE f.status WHEN 'open' THEN 0 ELSE 1 END, CASE f.kind WHEN 'vulnerability' THEN 0 WHEN 'eol' THEN 1 ELSE 2 END,
        CASE f.severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END, f.known_exploited DESC, f.title, f.id";

    public function __construct(private Connection $connection)
    {
    }

    public function ofSubject(string $teamId, string $subjectType, string $subjectId, \DateTimeImmutable $today, PageRequest $page): Page
    {
        $base = $this->connection->createQueryBuilder()->from('exposure_finding', 'f')
            ->where('f.team_id = :team')->andWhere('f.subject_type = :type')->andWhere('f.subject_id = :subject')
            ->setParameter('team', $teamId)->setParameter('type', $subjectType)->setParameter('subject', $subjectId);
        $total = Row::int((clone $base)->select('COUNT(*)')->fetchOne());
        $filtered = clone $base;
        if ('' !== $page->search) {
            $filtered->andWhere('(LOWER(f.title) LIKE :search OR LOWER(f.package) LIKE :search OR LOWER(f.finding_key) LIKE :search OR LOWER(CAST(f.aliases AS TEXT)) LIKE :search)')
                ->setParameter('search', '%'.u($page->search)->lower()->replace('\\', '\\\\')->replace('%', '\\%')->replace('_', '\\_')->toString().'%');
        }

        $count = Row::int((clone $filtered)->select('COUNT(*)')->fetchOne());
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select($filtered)->setParameter('today', $today->format('Y-m-d'))->orderBy(self::ORDER)->setFirstResult($page->offset)->setMaxResults($page->limit)->fetchAllAssociative();

        return new Page(array_map(self::row(...), $rows), $count, $total);
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
            'f.eol_level', 'f.end_of_life', 'f.latest', 'f.aliases', 'f.status', 'f.first_seen_at', 'f.last_seen_at', 'f.fixed_at', self::ACCEPTED_UNTIL);
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
        );
    }
}
