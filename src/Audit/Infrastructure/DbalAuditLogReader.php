<?php

declare(strict_types=1);

namespace Audit\Infrastructure;

use Audit\Application\Query\AuditLog\AuditCursor;
use Audit\Application\Query\AuditLog\AuditEntryView;
use Audit\Application\Query\AuditLog\AuditLogFilter;
use Audit\Application\Query\AuditLog\AuditLogReader;
use Audit\Application\Query\AuditLog\AuditPage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keyset pagination, newest first, filters bound as parameters (no string interpolation of client input).
 */
final readonly class DbalAuditLogReader implements AuditLogReader
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function page(AuditLogFilter $filter, ?AuditCursor $after, int $limit): AuditPage
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id', 'occurred_at', 'actor_type', 'actor_id', 'action', 'target_type', 'target_id', 'team_id', 'outcome', 'reason', 'host(ip) AS ip', 'request_id', 'context')
            ->from('audit.audit_log')
            ->orderBy('occurred_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit + 1);

        if (null !== $filter->teamId) {
            $query->andWhere('team_id = :team')->setParameter('team', $filter->teamId);
        }
        if (null !== $filter->outcome) {
            $query->andWhere('outcome = :outcome')->setParameter('outcome', $filter->outcome->value);
        }
        if (null !== $after) {
            $query->andWhere('(occurred_at, id) < (:after_at, :after_id)')
                ->setParameter('after_at', $after->occurredAt->format('Y-m-d H:i:s.uP'))
                ->setParameter('after_id', $after->id, ParameterType::STRING);
        }

        /** @var list<array<string, ?string>> $rows */
        $rows = $query->executeQuery()->fetchAllAssociative();
        $next = null;
        if (\count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[\count($rows) - 1];
            $next = AuditCursor::at(new \DateTimeImmutable((string) $last['occurred_at']), (string) $last['id']);
        }

        return new AuditPage(array_map(static fn (array $row): AuditEntryView => new AuditEntryView(
            (string) $row['id'],
            new \DateTimeImmutable((string) $row['occurred_at']),
            (string) $row['actor_type'],
            $row['actor_id'],
            (string) $row['action'],
            (string) $row['target_type'],
            $row['target_id'],
            $row['team_id'],
            (string) $row['outcome'],
            (string) $row['reason'],
            $row['ip'],
            (string) $row['request_id'],
            self::context((string) $row['context']),
        ), $rows), $next);
    }

    /**
     * @return array<string, string|int|bool>
     */
    private static function context(string $json): array
    {
        $decoded = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);

        return \is_array($decoded)
            ? array_filter($decoded, static fn (mixed $value, int|string $key): bool => \is_string($key) && \is_scalar($value) && !\is_float($value), \ARRAY_FILTER_USE_BOTH)
            : [];
    }
}
