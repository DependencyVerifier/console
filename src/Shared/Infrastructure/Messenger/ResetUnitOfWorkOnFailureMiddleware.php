<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Shared\Application\Audit\AuditableCommand;
use Shared\Domain\Model\RecordsEvents;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Shared\Infrastructure\Audit\FailureWithScope;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * A rollback undoes the database, not the EntityManager. On failure, in this order:
 * 1. resolve the audit scope while the target is still in the identity map;
 * 2. purge the unpublished events of the managed entities (safety belt: detached entities are not collected);
 * 3. clear the EntityManager, or reset it when it is closed.
 * The mutations and events of a cancelled command can then never resurface in a later flush of the same request.
 */
final readonly class ResetUnitOfWorkOnFailureMiddleware implements MiddlewareInterface
{
    public function __construct(private ManagerRegistry $doctrine, private AuditEntryFactory $entries)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $exception) {
            $command = $envelope->getMessage();
            $scope = $command instanceof AuditableCommand ? $this->entries->scopeOf($command->auditTarget()) : null;

            $this->resetUnitOfWork();

            throw null !== $scope ? new FailureWithScope($exception, $scope) : $exception;
        }
    }

    private function resetUnitOfWork(): void
    {
        $manager = $this->doctrine->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            return;
        }

        foreach ($manager->getUnitOfWork()->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof RecordsEvents) {
                    $entity->releaseEvents();
                }
            }
        }

        if ($manager->isOpen()) {
            $manager->clear();
        } else {
            $this->doctrine->resetManager();
        }
    }
}
