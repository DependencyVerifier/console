<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Messenger;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Just inside doctrine_transaction (after BEGIN): bounds lock waits of the command transaction. SET LOCAL only
 * applies inside a transaction, hence this position. A FOR UPDATE or pg_advisory_xact_lock waiting longer fails
 * with SQLSTATE 55P03, audited once as `failure` / `technical`.
 */
final readonly class LockTimeoutMiddleware implements MiddlewareInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->connection->executeStatement("SET LOCAL lock_timeout = '5s'");

        return $stack->next()->handle($envelope, $stack);
    }
}
