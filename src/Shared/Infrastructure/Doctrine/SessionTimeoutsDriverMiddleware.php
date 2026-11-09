<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Bounds waits on the "rate_limiter" connection (limiter states and advisory locks). Set where the connection is
 * born: DBAL drops `options` from a URL DSN. Autocommit connection, so a session-level SET applies (unlike SET LOCAL).
 * A wait beyond the bound fails with SQLSTATE 55P03 / 57014, translated into a fail-closed refusal.
 */
#[AsMiddleware(connections: ['rate_limiter'])]
final class SessionTimeoutsDriverMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                $connection = parent::connect($params);
                $connection->exec("SET lock_timeout = '2s'");
                $connection->exec("SET statement_timeout = '5s'");

                return $connection;
            }
        };
    }
}
