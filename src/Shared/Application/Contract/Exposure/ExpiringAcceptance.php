<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Exposure;

final readonly class ExpiringAcceptance
{
    public function __construct(public string $acceptanceId, public string $findingId, public string $teamId, public \DateTimeImmutable $expiresOn)
    {
    }
}
