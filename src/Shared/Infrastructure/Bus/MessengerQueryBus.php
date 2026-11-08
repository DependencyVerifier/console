<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Bus;

use Shared\Application\Bus\Query;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerQueryBus implements QueryBus
{
    use HandleTrait;
    use UnwrapsHandlerFailure;

    public function __construct(
        #[Autowire(service: 'query.bus')]
        MessageBusInterface $bus,
    ) {
        $this->messageBus = $bus;
    }

    public function ask(Query $query): mixed
    {
        return $this->unwrap(fn (): mixed => $this->handle($query));
    }
}
