<?php

declare(strict_types=1);

namespace Notification\Application\Query\View;

final readonly class RuleRow
{
    /**
     * @param list<string> $events
     * @param list<string> $webhooks names
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $environment,
        public array $events,
        public ?string $minSeverity,
        public ?float $minCvss,
        public bool $knownExploitedOnly,
        public ?float $minEpss,
        public int $silentHours,
        public array $webhooks,
        public bool $emailSubscribers,
    ) {
    }
}
