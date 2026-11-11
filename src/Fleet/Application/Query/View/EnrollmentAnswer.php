<?php

declare(strict_types=1);

namespace Fleet\Application\Query\View;

final readonly class EnrollmentAnswer
{
    public function __construct(public string $agentId, public string $status, public string $fingerprint, public string $consoleKeyId, public int $nextPollIn)
    {
    }
}
