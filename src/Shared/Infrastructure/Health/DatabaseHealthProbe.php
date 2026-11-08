<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Health;

use Doctrine\DBAL\Connection;
use Shared\Application\Health\HealthProbe;
use Shared\Domain\Health\ProbeResult;

final readonly class DatabaseHealthProbe implements HealthProbe
{
    public const string NAME = 'database';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function check(): ProbeResult
    {
        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            return ProbeResult::down(self::NAME, $exception->getMessage());
        }

        return ProbeResult::up(self::NAME);
    }
}
