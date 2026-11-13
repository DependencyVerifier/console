<?php

declare(strict_types=1);

namespace Notification\Application\Query;

final readonly class Digest
{
    /**
     * @param list<string> $deliveryIds
     */
    public function __construct(public string $userId, public string $frequency, public array $deliveryIds)
    {
    }
}
