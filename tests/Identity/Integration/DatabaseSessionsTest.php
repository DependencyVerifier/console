<?php

declare(strict_types=1);

namespace Tests\Identity\Integration;

use Identity\Infrastructure\Session\DatabaseSessionHandlerFactory;
use Identity\Infrastructure\Session\SessionDsnFactory;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

/**
 * The real PdoSessionHandler, on its own native PDO connection under the runtime role (tests use mock sessions).
 */
final class DatabaseSessionsTest extends TestCase
{
    #[Test]
    public function it_should_write_read_and_destroy_a_session_as_the_runtime_role(): void
    {
        $url = $this->databaseUrl();
        $handler = DatabaseSessionHandlerFactory::create($url);
        $id = bin2hex(random_bytes(16));

        self::assertTrue($handler->open('', 'sessions'));
        self::assertSame('', $handler->read($id));
        self::assertTrue($handler->write($id, 'payload'));
        self::assertTrue($handler->close());
        $handler->open('', 'sessions');
        self::assertSame('payload', $handler->read($id));
        self::assertTrue($handler->destroy($id));
        $handler->close();

        $connection = SessionDsnFactory::fromUrl($url);
        $pdo = new \PDO($connection['dsn'], $connection['username'], $connection['password']);
        foreach (['SELECT current_user' => 'depverify_app', 'SHOW lock_timeout' => '2s', 'SHOW statement_timeout' => '5s'] as $sql => $expected) {
            $statement = $pdo->query($sql);
            self::assertInstanceOf(\PDOStatement::class, $statement);
            self::assertSame($expected, $statement->fetchColumn());
        }
    }

    #[Test]
    public function it_should_build_a_native_dsn_without_credentials(): void
    {
        $connection = SessionDsnFactory::fromUrl('postgresql://depverify_app:p%40ss%3Bword@db.internal:6432/app?serverVersion=18&sslmode=verify-full');

        self::assertSame("pgsql:host=db.internal;port=6432;dbname=app;options='-c lock_timeout=2000 -c statement_timeout=5000';sslmode=verify-full", $connection['dsn']);
        self::assertSame('depverify_app', $connection['username']);
        self::assertSame('p@ss;word', $connection['password']);
        self::assertStringNotContainsString('password=', $connection['dsn']);
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function it_should_refuse_an_injected_host_or_database(): void
    {
        foreach (['postgresql://u:p@host;x=1:5432/app', "postgresql://u:p@host:5432/app'x"] as $url) {
            try {
                SessionDsnFactory::fromUrl($url);
                self::fail($url);
            } catch (\InvalidArgumentException) {
            }
        }
    }

    private function databaseUrl(): string
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($url);

        return u($url)->replace('/app?', '/app_test?')->toString();
    }
}
