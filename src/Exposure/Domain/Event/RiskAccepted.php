<?php

declare(strict_types=1);

namespace Exposure\Domain\Event;

use Shared\Domain\Event\DomainEvent;

final readonly class RiskAccepted implements DomainEvent
{
    public function __construct(public string $acceptanceId, public string $findingId, public string $teamId, public string $expiresOn)
    {
    }
}
