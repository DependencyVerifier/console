<?php

declare(strict_types=1);

namespace Notification\Application\Query\Handler;

use Notification\Application\Query\ListDueDeliveries;
use Notification\Domain\Model\Delivery;
use Notification\Domain\Repository\DeliveryRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryHandler;

final readonly class ListDueDeliveriesHandler implements QueryHandler
{
    public function __construct(private DeliveryRepository $deliveries, private ClockInterface $clock)
    {
    }

    /**
     * @return list<string>
     */
    public function __invoke(ListDueDeliveries $query): array
    {
        return array_map(static fn (Delivery $d): string => $d->id()->toRfc4122(), $this->deliveries->due($this->clock->now(), $query->limit));
    }
}
