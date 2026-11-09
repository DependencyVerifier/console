<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Session;

use Doctrine\DBAL\Tools\DsnParser;

use function Symfony\Component\String\u;

/**
 * Native PDO DSN of the session handler, built from DATABASE_URL: its own connection (never Doctrine's), runtime
 * role, with session-level timeouts in libpq `options` (a URL DSN would silently drop them). User and password go
 * through PdoSessionHandler's db_username / db_password options, never into the DSN.
 */
final class SessionDsnFactory
{
    private const string TIMEOUTS = '-c lock_timeout=2000 -c statement_timeout=5000';

    /**
     * @return array{dsn: string, username: string, password: string}
     */
    public static function fromUrl(#[\SensitiveParameter] string $url): array
    {
        $params = new DsnParser(['postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql'])->parse($url);
        $host = (string) ($params['host'] ?? 'localhost');
        $dbname = (string) ($params['dbname'] ?? '');
        $port = (int) ($params['port'] ?? 5432);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('The database port must be numeric.');
        }

        foreach ([$host, $dbname] as $part) {
            if ('' === $part || u($part)->containsAny([';', "'", ' '])) {
                throw new \InvalidArgumentException('Invalid database host or name.');
            }
        }

        $dsn = \sprintf("pgsql:host=%s;port=%d;dbname=%s;options='%s'", $host, $port, $dbname, self::TIMEOUTS);
        $sslMode = $params['sslmode'] ?? null;
        if (\is_string($sslMode) && [] !== u($sslMode)->match('/^[a-z-]+$/')) {
            $dsn .= ';sslmode='.$sslMode;
        }

        return ['dsn' => $dsn, 'username' => (string) ($params['user'] ?? ''), 'password' => (string) ($params['password'] ?? '')];
    }
}
