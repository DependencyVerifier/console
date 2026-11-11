<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Shared\Application\Content\TeamContentEraser;

final class RecordingEraser implements TeamContentEraser
{
    /** @var list<string> */
    public array $erased = [];

    public function eraseTeam(string $teamId): void
    {
        $this->erased[] = $teamId;
    }
}
