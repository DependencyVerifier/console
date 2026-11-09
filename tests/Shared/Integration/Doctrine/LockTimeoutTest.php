<?php

declare(strict_types=1);

namespace Tests\Shared\Integration\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Fixtures\Messenger\FixtureCommand;
use Tests\Support\AuditLog;
use Tests\Support\FixtureSchema;
use Tests\Support\OwnerConnection;

/**
 * A command waiting for a row lock gives up after 5 s (SET LOCAL lock_timeout after BEGIN), audited once as a
 * technical failure: no worker waits forever.
 */
final class LockTimeoutTest extends KernelTestCase
{
    #[Test]
    public function it_should_fail_closed_when_a_lock_is_held_too_long(): void
    {
        FixtureSchema::ensure();
        $id = Uuid::v7()->toRfc4122();
        $holder = OwnerConnection::runtime();
        $holder->insert('fixture_aggregate', ['id' => $id, 'name' => 'locked']);
        $holder->beginTransaction();
        $holder->fetchOne('SELECT id FROM fixture_aggregate WHERE id = :id FOR UPDATE', ['id' => $id]);

        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $start = microtime(true);
        try {
            self::getContainer()->get(CommandBus::class)->dispatch(new FixtureCommand('lock', $id));
            self::fail('The command should have timed out.');
        } catch (AlreadyAudited $audited) {
            self::assertSame('55P03', $audited->cause() instanceof DriverException ? $audited->cause()->getSQLState() : null);
        } finally {
            $holder->rollBack();
            $holder->delete('fixture_aggregate', ['id' => $id]);
            $holder->close();
        }

        self::assertGreaterThan(4.5, microtime(true) - $start);
        self::assertLessThan(8.0, microtime(true) - $start);
        $entries = AuditLog::entries($connection, 'fixture.lock');
        self::assertCount(1, $entries);
        self::assertSame(['failure', 'technical'], [$entries[0]['outcome'], $entries[0]['reason']]);
    }
}
