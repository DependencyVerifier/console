<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

/**
 * One snapshot of a subject in one inventory: `changed` when its digest differs from the previous one of the same
 * subject (the first one kept counts as changed).
 */
final readonly class HistoryRow
{
    public function __construct(
        public \DateTimeImmutable $takenAt,
        public string $subject,
        public string $digest,
        public bool $changed,
        public ?string $trigger,
    ) {
    }
}
