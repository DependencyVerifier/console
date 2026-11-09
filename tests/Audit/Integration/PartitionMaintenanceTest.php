<?php

declare(strict_types=1);

namespace Tests\Audit\Integration;

use Audit\Infrastructure\PartitionMaintenance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Support\OwnerConnection;

/**
 * Maintenance as the owner role: rows that landed in DEFAULT move to their new monthly partition, the next months
 * exist, expired partitions are dropped, and the append-only protections hold right after.
 */
final class PartitionMaintenanceTest extends TestCase
{
    private Connection $owner;

    protected function setUp(): void
    {
        $this->owner = OwnerConnection::open();
    }

    protected function tearDown(): void
    {
        foreach (['audit_log_2030_05', 'audit_log_2001_01'] as $partition) {
            if (false !== $this->owner->fetchOne('SELECT to_regclass(:name)', ['name' => 'audit.'.$partition]) && null !== $this->owner->fetchOne('SELECT to_regclass(:name)', ['name' => 'audit.'.$partition])) {
                $this->owner->executeStatement("ALTER TABLE audit.audit_log DETACH PARTITION audit.{$partition}");
                $this->owner->executeStatement("DROP TABLE audit.{$partition}");
            }
        }
        $this->owner->close();
    }

    #[Test]
    public function it_should_move_rows_out_of_default_then_keep_them_append_only(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->owner->insert('audit.audit_log', ['id' => $id, 'occurred_at' => '2030-05-17 10:00:00+00', 'actor_type' => 'cli', 'action' => 'test.future', 'target_type' => 'none', 'outcome' => 'success', 'request_id' => 'r']);
        self::assertEquals(1, $this->owner->fetchOne('SELECT count(*) FROM audit.audit_log_default WHERE id = :id', ['id' => $id]));

        $result = $this->maintenance(new \DateTimeImmutable('2026-11-09'))->run();

        self::assertSame(['2030-05-01'], $result['moved']);
        self::assertSame(13, $result['created']);
        self::assertEquals(1, $this->owner->fetchOne('SELECT count(*) FROM audit.audit_log_2030_05 WHERE id = :id', ['id' => $id]));
        self::assertEquals(0, $this->owner->fetchOne('SELECT count(*) FROM audit.audit_log_default WHERE id = :id', ['id' => $id]));

        $runtime = OwnerConnection::runtime();
        foreach (['UPDATE audit.audit_log_2030_05 SET reason = \'x\'', 'DELETE FROM audit.audit_log_2030_05', 'TRUNCATE audit.audit_log_2030_05', 'DELETE FROM audit.audit_log_default'] as $statement) {
            try {
                $runtime->executeStatement($statement);
                self::fail(\sprintf('"%s" should have been refused.', $statement));
            } catch (Exception $refused) {
                self::assertStringContainsString('permission denied', $refused->getMessage());
            }
        }
        $runtime->close();

        // The triggers are back and enabled on DEFAULT after its DETACH / ATTACH (a DELETE on an empty table
        // would fire no row trigger, so the catalogue is checked instead).
        self::assertSame(
            [['tgname' => 'audit_log_append_only', 'tgenabled' => 'O'], ['tgname' => 'audit_log_no_truncate', 'tgenabled' => 'O']],
            $this->owner->fetchAllAssociative("SELECT tgname, tgenabled FROM pg_trigger WHERE tgrelid = 'audit.audit_log_default'::regclass AND NOT tgisinternal ORDER BY tgname"),
        );
    }

    #[Test]
    public function it_should_drop_partitions_older_than_the_retention(): void
    {
        $this->owner->executeStatement("SELECT audit.ensure_month_partition('2001-01-01'::date)");

        $result = $this->maintenance(new \DateTimeImmutable('2026-11-09'))->run();

        self::assertContains('audit_log_2001_01', $result['dropped']);
        self::assertNull($this->owner->fetchOne("SELECT to_regclass('audit.audit_log_2001_01')"));
        self::assertNotContains('audit_log_'.new \DateTimeImmutable()->format('Y_m'), $result['dropped']);
    }

    private function maintenance(\DateTimeImmutable $now): PartitionMaintenance
    {
        return new PartitionMaintenance($this->owner, new MockClock($now), 365);
    }
}
