<?php

declare(strict_types=1);

namespace Fleet\Application\Query\View;

/**
 * The fleet of a team as the actor sees it: the gestures it may do, and the console key the agents pin.
 */
final readonly class FleetView
{
    /**
     * @param array<string, bool> $can permission value => granted
     */
    public function __construct(public string $teamId, public string $teamName, public array $can, public string $consoleKey, public string $consoleKeyId)
    {
    }

    public function can(string $permission): bool
    {
        return $this->can[$permission] ?? false;
    }
}
