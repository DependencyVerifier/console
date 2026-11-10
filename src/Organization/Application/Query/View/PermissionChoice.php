<?php

declare(strict_types=1);

namespace Organization\Application\Query\View;

/**
 * One ○ permission of the member's role: granted or not, and whether the actor holds it (a permission is granted
 * only by whom holds it; revoking needs no holding).
 */
final readonly class PermissionChoice
{
    public function __construct(public string $permission, public bool $granted, public bool $actorHolds)
    {
    }

    public function isGrantableByActor(): bool
    {
        return $this->granted || $this->actorHolds;
    }
}
