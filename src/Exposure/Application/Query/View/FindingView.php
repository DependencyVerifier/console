<?php

declare(strict_types=1);

namespace Exposure\Application\Query\View;

/**
 * A finding with the history of its acceptances (the latest first) and whether the actor may decide on it.
 */
final readonly class FindingView
{
    /**
     * @param list<AcceptanceView> $acceptances
     */
    public function __construct(public FindingRow $finding, public array $acceptances, public bool $mayDecide)
    {
    }

    public function runningAcceptance(): ?AcceptanceView
    {
        return array_find($this->acceptances, static fn (AcceptanceView $a): bool => $a->active);
    }
}
