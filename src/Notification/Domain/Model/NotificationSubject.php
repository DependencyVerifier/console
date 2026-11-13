<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

/**
 * What a rule judges of an event: its kind, where it happened (no project: the fleet), for a vulnerability its gravity,
 * for a silent agent how long it has been silent.
 */
final readonly class NotificationSubject
{
    public function __construct(
        public NotificationEvent $event,
        public ?string $projectId = null,
        public ?string $environmentId = null,
        public ?string $severity = null,
        public ?float $cvss = null,
        public bool $knownExploited = false,
        public ?float $epss = null,
        public int $silentHours = 0,
    ) {
    }
}
