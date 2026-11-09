<?php

declare(strict_types=1);

namespace Shared\Infrastructure\RateLimiting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Shared\Application\Security\RateLimiterStorageUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Policy\TokenBucket;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Limiter states in `rate_limiter_state`, on the dedicated autocommit connection (a command rollback never gives a
 * consumed token back). Unlike a cache pool, errors are not swallowed: every DBAL failure surfaces as
 * RateLimiterStorageUnavailable (fail-closed), and there is never a retry, because the same connection holds the
 * advisory locks and a reconnection would silently lose them.
 */
final readonly class DbalRateLimiterStorage implements StorageInterface
{
    private const array STATE_CLASSES = [SlidingWindow::class, Window::class, TokenBucket::class];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.rate_limiter_connection')]
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $expiresAt = $this->clock->now()->modify(\sprintf('+%d seconds', max(1, $limiterState->getExpirationTime() ?? 86_400)));

        // Base64: serialized private properties hold NUL bytes, which a PostgreSQL TEXT column cannot store.
        $this->guard(fn () => $this->connection->executeStatement(
            'INSERT INTO rate_limiter_state (id, state, expires_at) VALUES (:id, :state, :expires)
             ON CONFLICT (id) DO UPDATE SET state = EXCLUDED.state, expires_at = EXCLUDED.expires_at',
            ['id' => $limiterState->getId(), 'state' => base64_encode(serialize($limiterState)), 'expires' => $expiresAt->format(\DATE_ATOM)],
        ));
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $state = $this->guard(fn () => $this->connection->fetchOne(
            'SELECT state FROM rate_limiter_state WHERE id = :id AND expires_at > :now',
            ['id' => $limiterStateId, 'now' => $this->clock->now()->format(\DATE_ATOM)],
        ));

        $raw = \is_string($state) ? base64_decode($state, true) : false;
        if (false === $raw) {
            return null;
        }

        $limiterState = unserialize($raw, ['allowed_classes' => self::STATE_CLASSES]);

        return $limiterState instanceof LimiterStateInterface ? $limiterState : null;
    }

    public function delete(string $limiterStateId): void
    {
        $this->guard(fn () => $this->connection->executeStatement('DELETE FROM rate_limiter_state WHERE id = :id', ['id' => $limiterStateId]));
    }

    /**
     * Deletes expired states in bounded batches (each under the connection's statement_timeout).
     */
    public function prune(int $batchSize = 1000): int
    {
        $deleted = 0;
        do {
            $batch = (int) $this->guard(fn () => $this->connection->executeStatement(
                'DELETE FROM rate_limiter_state WHERE id IN (SELECT id FROM rate_limiter_state WHERE expires_at <= :now LIMIT :limit)',
                ['now' => $this->clock->now()->format(\DATE_ATOM), 'limit' => $batchSize],
                ['limit' => ParameterType::INTEGER],
            ));
            $deleted += $batch;
        } while ($batch === $batchSize);

        return $deleted;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $failure) {
            $translated = RateLimiterStorageFailure::translate($failure);

            throw $translated instanceof RateLimiterStorageUnavailable ? $translated : new RateLimiterStorageUnavailable('Rate limiter storage unavailable.', 0, $failure);
        }
    }
}
