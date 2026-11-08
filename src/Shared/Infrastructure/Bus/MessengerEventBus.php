<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Bus;

use Shared\Application\Bus\EventBus;
use Shared\Domain\Event\DomainEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerEventBus implements EventBus
{
    use UnwrapsHandlerFailure;

    public function __construct(
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $bus,
    ) {
    }

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->unwrap(fn () => $this->bus->dispatch($event));
        }
    }
}
