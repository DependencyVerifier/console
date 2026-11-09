<?php

declare(strict_types=1);

namespace Shared\Application\Content;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by each context holding data attached to a project (Fleet, Inventory, Exposure…): a project or an
 * environment with content cannot be deleted. None in this step.
 */
#[AutoconfigureTag]
interface ProjectContentProbe
{
    public function projectHasContent(string $projectId): bool;

    public function environmentHasContent(string $environmentId): bool;
}
