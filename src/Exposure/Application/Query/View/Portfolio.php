<?php

declare(strict_types=1);

namespace Exposure\Application\Query\View;

/**
 * The production of every team of the actor, and its most urgent findings across the teams.
 */
final readonly class Portfolio
{
    /**
     * @param list<TeamProduction> $teams
     * @param list<FindingRow>     $urgent
     */
    public function __construct(public array $teams, public array $urgent)
    {
    }
}
