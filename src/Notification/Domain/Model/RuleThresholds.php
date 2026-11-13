<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

/**
 * The thresholds of a rule for the vulnerabilities (each one null: no threshold, all of them must hold), and the silence
 * after which an agent is reported.
 */
final readonly class RuleThresholds
{
    public function __construct(
        public ?string $minSeverity = null,
        public ?float $minCvss = null,
        public bool $knownExploitedOnly = false,
        public ?float $minEpss = null,
        public int $silentHours = 24,
    ) {
        if (null !== $minSeverity && !isset(NotificationRule::SEVERITIES[$minSeverity])) {
            throw new InvalidNotificationSetting('A severity: low, medium, high or critical.');
        }

        if (null !== $minCvss && ($minCvss < 0.0 || $minCvss > 10.0)) {
            throw new InvalidNotificationSetting('A CVSS score between 0 and 10.');
        }

        if (null !== $minEpss && ($minEpss < 0.0 || $minEpss > 1.0)) {
            throw new InvalidNotificationSetting('An EPSS probability between 0 and 1.');
        }

        if ($silentHours < 1 || $silentHours > 720) {
            throw new InvalidNotificationSetting('A silence of 1 hour to 30 days.');
        }
    }
}
