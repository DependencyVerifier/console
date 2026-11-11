<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Agent;

use Shared\Application\Bus\Query;

/**
 * The answer to a manifest: the digests the team does not hold yet (empty when nothing changed).
 *
 * @implements Query<list<string>>
 */
final readonly class GetMissingSnapshots implements Query
{
    public function __construct(public string $agentId, public string $inventoryId)
    {
    }
}
