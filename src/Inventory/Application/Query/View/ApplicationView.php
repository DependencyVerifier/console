<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

use Shared\Application\Contract\Organization\TeamEnvironment;

/**
 * One application as the actor sees it, with where it may attach it (project.apps.assign) and whether it may detach it.
 */
final readonly class ApplicationView
{
    /**
     * @param list<TeamEnvironment> $assignable
     */
    public function __construct(public AppRow $app, public array $assignable, public bool $mayDetach, public ?string $imageRecordId)
    {
    }
}
