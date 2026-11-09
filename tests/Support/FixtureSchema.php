<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Creates the table of Tests\Fixtures\Doctrine\FixtureAggregate (owner role; the runtime role gets DML through the
 * default privileges of the public schema).
 */
final class FixtureSchema
{
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }

        $owner = OwnerConnection::open();
        $owner->executeStatement('CREATE TABLE IF NOT EXISTS fixture_aggregate (id VARCHAR(36) NOT NULL PRIMARY KEY, name VARCHAR(64) NOT NULL)');
        $owner->close();
        self::$ready = true;
    }
}
