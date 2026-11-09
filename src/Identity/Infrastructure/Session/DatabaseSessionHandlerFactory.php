<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Session;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Sessions in PostgreSQL (decision 29), table `sessions`, advisory locks (better suited to the worker mode than a
 * transaction held for the whole request).
 */
final class DatabaseSessionHandlerFactory
{
    public static function create(#[Autowire(env: 'DATABASE_URL')] #[\SensitiveParameter] string $databaseUrl): PdoSessionHandler
    {
        $connection = SessionDsnFactory::fromUrl($databaseUrl);

        return new PdoSessionHandler($connection['dsn'], [
            'db_table' => 'sessions',
            'db_username' => $connection['username'],
            'db_password' => $connection['password'],
            'lock_mode' => PdoSessionHandler::LOCK_ADVISORY,
        ]);
    }
}
