<?php

declare(strict_types=1);

namespace Notification\Application\Query\Handler;

use Notification\Application\Query\Digest;
use Notification\Application\Query\ListDigests;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Repository\DeliveryRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryHandler;

final readonly class ListDigestsHandler implements QueryHandler
{
    public function __construct(private DeliveryRepository $deliveries, private ClockInterface $clock)
    {
    }

    /**
     * @return list<Digest>
     */
    public function __invoke(ListDigests $query): array
    {
        $frequencies = '1' === $this->clock->now()->format('N') ? [Frequency::Daily, Frequency::Weekly] : [Frequency::Daily];
        $digests = [];
        foreach ($frequencies as $frequency) {
            $byUser = [];
            foreach ($this->deliveries->pendingDigest($frequency, $query->limit) as $delivery) {
                $byUser[$delivery->target()][] = $delivery->id()->toRfc4122();
            }

            foreach ($byUser as $userId => $ids) {
                $digests[] = new Digest((string) $userId, $frequency->value, $ids);
            }
        }

        return $digests;
    }
}
