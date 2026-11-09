<?php

declare(strict_types=1);

namespace Tests\Audit\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The runtime role (that of the web workers) can only INSERT and SELECT on the audit log, on the parent table as on
 * every partition: no UPDATE, DELETE, TRUNCATE or DROP. Triggers refuse the same operations even to the owner.
 */
final class AppendOnlyAuditLogTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    #[Test]
    public function it_should_run_as_the_unprivileged_runtime_role(): void
    {
        self::assertSame(
            ['current_user' => 'depverify_app', 'rolsuper' => false],
            $this->connection->fetchAssociative('SELECT current_user, rolsuper FROM pg_roles WHERE rolname = current_user'),
        );
    }

    #[Test]
    public function it_should_let_the_runtime_role_insert_and_read(): void
    {
        $id = $this->insert();

        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM audit.audit_log WHERE id = :id', ['id' => $id]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideForbiddenStatements(): iterable
    {
        $partition = 'audit.audit_log_'.new \DateTimeImmutable()->format('Y_m');
        foreach (['audit.audit_log', $partition, 'audit.audit_log_default'] as $table) {
            yield "update {$table}" => ["UPDATE {$table} SET reason = 'tampered'"];
            yield "delete {$table}" => ["DELETE FROM {$table}"];
            yield "truncate {$table}" => ["TRUNCATE {$table}"];
            yield "drop {$table}" => ["DROP TABLE {$table}"];
        }
        yield 'disable triggers' => ['ALTER TABLE audit.audit_log DISABLE TRIGGER USER'];
        yield 'drop schema' => ['DROP SCHEMA audit CASCADE'];
    }

    #[Test]
    #[DataProvider('provideForbiddenStatements')]
    public function it_should_refuse_any_change_to_the_runtime_role(string $statement): void
    {
        $this->insert();

        $this->connection->beginTransaction(); // savepoint inside the test transaction
        try {
            $this->connection->executeStatement($statement);
            self::fail(\sprintf('"%s" should have been refused.', $statement));
        } catch (Exception $refused) {
            self::assertMatchesRegularExpression('/permission denied|must be owner|append-only/', $refused->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    private function insert(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('audit.audit_log', ['id' => $id, 'occurred_at' => 'now()', 'actor_type' => 'cli', 'action' => 'test.insert', 'target_type' => 'none', 'outcome' => 'success', 'request_id' => 'r']);

        return $id;
    }
}
