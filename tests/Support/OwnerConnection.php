<?php

declare(strict_types=1);

namespace Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Connection to the test database as the owner role (DDL, partition maintenance), from TEST_OWNER_DATABASE_URL
 * passed by `make test`. Committed, outside the per-test transaction: clean up what you create.
 */
final class OwnerConnection
{
    public static function open(): Connection
    {
        $url = getenv('TEST_OWNER_DATABASE_URL');
        if (!\is_string($url) || '' === $url) {
            throw new \RuntimeException('TEST_OWNER_DATABASE_URL is not set: run the tests with `make test`.');
        }

        $params = new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url);
        $params['dbname'] = ($params['dbname'] ?? 'app').'_test';

        return DriverManager::getConnection($params);
    }

    /**
     * A second runtime-role connection (its own session: separate locks), from the test DATABASE_URL.
     */
    public static function runtime(): Connection
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if (!\is_string($url)) {
            throw new \RuntimeException('DATABASE_URL is not set.');
        }

        $params = new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url);
        $params['dbname'] = ($params['dbname'] ?? 'app').'_test';

        return DriverManager::getConnection($params);
    }
}
