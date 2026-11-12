<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Inventory;

use Shared\Application\Bus\Query;

/**
 * Published query of Inventory: the team and the environment (null: not attached) of an application, null when
 * there is no such application. No visibility check: the asking context judges with Organization.
 *
 * @implements Query<?array{teamId: string, environmentId: ?string}>
 */
final readonly class GetApplicationPlacement implements Query
{
    public function __construct(public string $applicationId)
    {
    }
}
