<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * The owner role (migrations, audit partitions) may only ever be used from the CLI. A web worker that would be
 * configured with it refuses to connect instead of running with DDL rights on the append-only audit log.
 */
#[AsMiddleware]
final class OwnerRoleGuardDriverMiddleware implements Middleware
{
    public const string OWNER_ROLE = 'depverify_owner';

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                if ('cli' !== \PHP_SAPI && OwnerRoleGuardDriverMiddleware::OWNER_ROLE === ($params['user'] ?? null)) {
                    throw new \LogicException('The owner database role is reserved to CLI commands.');
                }

                return parent::connect($params);
            }
        };
    }
}
