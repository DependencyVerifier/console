<?php

declare(strict_types=1);

namespace Exposure\Application\Query\View;

/**
 * The figures of a perimeter: open findings not accepted, by severity (vulnerabilities), by end of life level, the
 * outdated dependencies, the accepted ones; what opened and what was fixed each of the last 30 days; the most exposed
 * applications; the ends of life to come, the nearest first.
 */
final readonly class ExposureSummary
{
    /**
     * @param array<string, int>                                                                                                 $severities      critical, high, medium, low, unknown
     * @param array<string, int>                                                                                                 $eolLevels       upcoming, soon, imminent, expired
     * @param list<array{day: string, opened: int, fixed: int}>                                                                  $trend
     * @param list<array{id: string, name: string, knownExploited: int, critical: int, high: int, total: int}>                   $topApplications
     * @param list<array{title: string, product: string, endOfLife: ?string, level: ?string, suggested: ?string, subjects: int}> $calendar
     */
    public function __construct(
        public array $severities,
        public int $knownExploited,
        public array $eolLevels,
        public int $outdated,
        public int $accepted,
        public array $trend,
        public array $topApplications,
        public array $calendar,
    ) {
    }

    public function vulnerabilities(): int
    {
        return array_sum($this->severities);
    }

    public function endsOfLife(): int
    {
        return array_sum($this->eolLevels);
    }
}
