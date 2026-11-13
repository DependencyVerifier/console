<?php

declare(strict_types=1);

namespace Exposure\Application\Query\View;

/**
 * The production part of a perimeter: whether it has a production environment at all, its figures, and its most
 * urgent findings.
 */
final readonly class ProductionExposure
{
    /**
     * @param list<FindingRow> $urgent
     */
    public function __construct(public bool $hasProduction, public ExposureSummary $summary, public array $urgent)
    {
    }

    /**
     * Something needs attention: an exploited or a critical or high vulnerability, or an end of life reached or close.
     */
    public function isQuiet(): bool
    {
        return [] === $this->urgent;
    }
}
