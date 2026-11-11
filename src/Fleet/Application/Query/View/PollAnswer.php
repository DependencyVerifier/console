<?php

declare(strict_types=1);

namespace Fleet\Application\Query\View;

final readonly class PollAnswer
{
    /**
     * @param list<array<string, mixed>> $orders signed envelopes, oldest first
     */
    public function __construct(public string $status, public array $orders, public int $nextPollIn)
    {
    }
}
