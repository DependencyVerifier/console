<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Fleet;

final readonly class SilentAgent
{
    public function __construct(public string $agentId, public string $teamId, public string $hostname, public \DateTimeImmutable $lastSeenAt)
    {
    }
}
