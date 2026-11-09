<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Shared\Domain\Event\DomainEvent;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects the events recorded by every managed aggregate at flush time (the whole identity map plus the scheduled
 * insertions, so an aggregate whose children changed but not itself is not missed). Published after the commit by
 * PublishDomainEventsMiddleware; emptied on failure and between requests (worker mode).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class DomainEventCollector implements ResetInterface
{
    /** @var list<DomainEvent> */
    private array $events = [];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        $seen = new \SplObjectStorage();
        $entities = $unitOfWork->getScheduledEntityInsertions();
        foreach ($unitOfWork->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                $entities[] = $entity;
            }
        }

        foreach ($entities as $entity) {
            if (!$entity instanceof RecordsEvents || $seen->offsetExists($entity)) {
                continue;
            }

            $seen->offsetSet($entity);
            foreach ($entity->releaseEvents() as $event) {
                $this->events[] = $event;
            }
        }
    }

    /**
     * @return list<DomainEvent>
     */
    public function release(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
