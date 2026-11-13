<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

use Shared\Application\Contract\Organization\TeamEnvironment;

/**
 * One application as the actor sees it, with where it may attach it (project.apps.assign) and whether it may detach it;
 * for its header, its frameworks and the OS under it (its image's, else its host's); whether the actor may edit its
 * documentation links.
 */
final readonly class ApplicationView
{
    /**
     * @param list<TeamEnvironment> $assignable
     * @param list<KeyPackage>      $frameworks
     */
    public function __construct(
        public AppRow $app,
        public array $assignable,
        public bool $mayDetach,
        public ?string $imageRecordId,
        public array $frameworks = [],
        public ?string $os = null,
        public bool $mayDocument = false,
    ) {
    }
}
