<?php

declare(strict_types=1);

namespace Tests\Organization\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\TeamRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Support\OwnerConnection;

/**
 * The concurrency guard of the quotas and of the last team admin: every command touching a team locks its row first
 * (TeamRepository::lockById, `SELECT … FOR UPDATE`), then counts. With two real PostgreSQL sessions, a second locker
 * waits for the first: two invitations can never both see the last free seat, two demotions can never both see two
 * admins. The order "lock, then count" of each handler is covered by the unit tests (LocksAndRanksTest).
 */
final class TeamLockTest extends KernelTestCase
{
    #[Test]
    public function it_should_make_a_second_command_wait_for_the_team_lock(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $holder = OwnerConnection::runtime();
        $holder->insert('organization_team', ['id' => $id, 'name' => 'Locked', 'slug' => 'locked-'.$id, 'admin_established' => 'true', 'created_at' => '2026-11-11 08:00:00']);
        $holder->beginTransaction();
        $holder->fetchOne('SELECT id FROM organization_team WHERE id = :id FOR UPDATE', ['id' => $id]);

        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $teams = self::getContainer()->get(TeamRepository::class);
        $start = microtime(true);
        $connection->beginTransaction();
        try {
            $connection->executeStatement("SET LOCAL lock_timeout = '1s'");
            $teams->lockById(TeamId::fromString($id));
            self::fail('The second locker should have waited, then given up.');
        } catch (\Throwable $waited) {
            $cause = $waited;
            while (!$cause instanceof DriverException && null !== $cause->getPrevious()) {
                $cause = $cause->getPrevious();
            }

            self::assertInstanceOf(DriverException::class, $cause);
            self::assertSame('55P03', $cause->getSQLState(), 'lock_not_available: it waited on the row lock.');
        } finally {
            $connection->rollBack();
            $holder->rollBack();
            $holder->delete('organization_team', ['id' => $id]);
            $holder->close();
        }

        self::assertGreaterThan(0.8, microtime(true) - $start);
    }

    #[Test]
    public function it_should_lock_at_once_a_team_nobody_holds(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $holder = OwnerConnection::runtime();
        $holder->insert('organization_team', ['id' => $id, 'name' => 'Free', 'slug' => 'free-'.$id, 'admin_established' => 'true', 'created_at' => '2026-11-11 08:00:00']);

        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $connection->beginTransaction();
        try {
            $team = self::getContainer()->get(TeamRepository::class)->lockById(TeamId::fromString($id));
            self::assertNotNull($team);
            self::assertSame('Free', $team->name());
        } finally {
            $connection->rollBack();
            $holder->delete('organization_team', ['id' => $id]);
            $holder->close();
        }
    }
}
