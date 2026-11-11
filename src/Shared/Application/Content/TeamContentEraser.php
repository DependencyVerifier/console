<?php

declare(strict_types=1);

namespace Shared\Application\Content;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by each context holding data attached to a team (Fleet first): called by DeleteTeam, inside its
 * transaction and under the team lock, so a deleted team leaves nothing behind (decision 16, cascade).
 */
#[AutoconfigureTag]
interface TeamContentEraser
{
    public function eraseTeam(string $teamId): void;
}
