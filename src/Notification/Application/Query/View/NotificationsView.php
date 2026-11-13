<?php

declare(strict_types=1);

namespace Notification\Application\Query\View;

/**
 * The notifications of a project, or of the fleet of the team (no project), as the actor sees them: what it may do,
 * its own subscription, and what a rule form offers.
 */
final readonly class NotificationsView
{
    /**
     * @param list<string> $events       the events a rule of this scope tells
     * @param list<Option> $webhooks     of the team, for the rule forms (empty unless it manages)
     * @param list<Option> $environments of the project, for the rule forms
     */
    public function __construct(
        public string $teamId,
        public ?string $projectId,
        public bool $manages,
        public bool $managesChannels,
        public bool $subscribes,
        public ?string $myFrequency,
        public array $events,
        public array $webhooks,
        public array $environments,
    ) {
    }
}
