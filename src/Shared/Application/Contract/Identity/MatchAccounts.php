<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Identity;

use Shared\Application\Bus\Query;

/**
 * Published query of Identity, for the searches of the member lists: the ids of the accounts whose email or display
 * name contains the term (case-insensitive), at most LIMIT. Ids only: the caller already restricts them to its own
 * scope (the members of a team or a project) and asks FindAccounts for what it shows.
 *
 * @implements Query<list<string>>
 */
final readonly class MatchAccounts implements Query
{
    public const int LIMIT = 500;

    public function __construct(public string $term)
    {
    }
}
