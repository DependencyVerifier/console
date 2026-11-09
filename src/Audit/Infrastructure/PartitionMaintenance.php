<?php

declare(strict_types=1);

namespace Audit\Infrastructure;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function Symfony\Component\String\u;

/**
 * Monthly partitions of audit.audit_log, run by the owner role (`make audit-partitions`):
 * 1. a month whose rows landed in DEFAULT gets its partition, and the rows are moved, in one transaction under an
 *    exclusive lock (DETACH DEFAULT, create, re-insert, delete from the detached table with its user triggers
 *    disabled, re-enable, ATTACH back);
 * 2. the next 12 months exist (privileges and TRUNCATE trigger reapplied by audit.ensure_month_partition);
 * 3. partitions entirely older than the retention are detached then dropped.
 * Idempotent.
 */
final readonly class PartitionMaintenance
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private ClockInterface $clock,
        #[Autowire(env: 'int:AUDIT_RETENTION_DAYS')]
        private int $retentionDays,
    ) {
    }

    /**
     * @return array{moved: list<string>, created: int, dropped: list<string>}
     */
    public function run(): array
    {
        $moved = $this->moveRowsOutOfDefault();
        $created = $this->ensureUpcomingMonths();
        $dropped = $this->dropExpired();

        return ['moved' => $moved, 'created' => $created, 'dropped' => $dropped];
    }

    /**
     * @return list<string> months moved, as YYYY-MM-01
     */
    private function moveRowsOutOfDefault(): array
    {
        /** @var list<string> $months */
        $months = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT to_char(date_trunc('month', occurred_at), 'YYYY-MM-DD') FROM audit.audit_log_default ORDER BY 1",
        );

        foreach ($months as $month) {
            $this->connection->transactional(static function (Connection $connection) use ($month): void {
                $connection->executeStatement('LOCK TABLE audit.audit_log IN ACCESS EXCLUSIVE MODE');
                $connection->executeStatement('ALTER TABLE audit.audit_log DETACH PARTITION audit.audit_log_default');
                $connection->executeStatement('SELECT audit.ensure_month_partition(:month::date)', ['month' => $month]);
                $range = "occurred_at >= :month::date AND occurred_at < (:month::date + INTERVAL '1 month')";
                $connection->executeStatement("INSERT INTO audit.audit_log SELECT * FROM audit.audit_log_default WHERE {$range}", ['month' => $month]);
                $connection->executeStatement('ALTER TABLE audit.audit_log_default DISABLE TRIGGER USER');
                $connection->executeStatement("DELETE FROM audit.audit_log_default WHERE {$range}", ['month' => $month]);
                $connection->executeStatement('ALTER TABLE audit.audit_log_default ENABLE TRIGGER USER');
                $connection->executeStatement('ALTER TABLE audit.audit_log ATTACH PARTITION audit.audit_log_default DEFAULT');
                $connection->executeStatement("SELECT audit.protect_partition('audit.audit_log_default'::regclass)");
            });
        }

        return $months;
    }

    private function ensureUpcomingMonths(): int
    {
        $created = 0;
        $month = $this->clock->now()->modify('first day of this month')->setTime(0, 0);
        for ($i = 0; $i <= 12; ++$i) {
            $this->connection->executeStatement('SELECT audit.ensure_month_partition(:month::date)', ['month' => $month->format('Y-m-d')]);
            $month = $month->modify('+1 month');
            ++$created;
        }

        return $created;
    }

    /**
     * @return list<string>
     */
    private function dropExpired(): array
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d days', $this->retentionDays));
        /** @var list<string> $partitions */
        $partitions = $this->connection->fetchFirstColumn(
            "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid WHERE i.inhparent = 'audit.audit_log'::regclass AND c.relname ~ '^audit_log_[0-9]{4}_[0-9]{2}$' ORDER BY 1",
        );

        $dropped = [];
        foreach ($partitions as $partition) {
            $month = \DateTimeImmutable::createFromFormat('!Y_m', u($partition)->after('audit_log_')->toString(), $cutoff->getTimezone());
            if (false === $month || $month->modify('+1 month') > $cutoff) {
                continue;
            }

            $this->connection->executeStatement(\sprintf('ALTER TABLE audit.audit_log DETACH PARTITION audit.%s', $partition));
            $this->connection->executeStatement(\sprintf('DROP TABLE audit.%s', $partition));
            $dropped[] = $partition;
        }

        return $dropped;
    }
}
