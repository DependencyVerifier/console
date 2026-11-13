<?php

declare(strict_types=1);

namespace Notification\Application\Command\Rule;

/**
 * What a rule form gives, as plain values: the events, the thresholds (null: none), the silence of an agent, the
 * webhooks to call, whether the subscribers are emailed, and an environment of the project (null: all of them).
 */
final readonly class RuleSettings
{
    /**
     * @param list<string> $events
     * @param list<string> $webhookIds
     */
    public function __construct(
        public string $name,
        public array $events,
        public ?string $environmentId = null,
        public ?string $minSeverity = null,
        public ?float $minCvss = null,
        public bool $knownExploitedOnly = false,
        public ?float $minEpss = null,
        public int $silentHours = 24,
        public array $webhookIds = [],
        public bool $emailSubscribers = true,
    ) {
    }
}
