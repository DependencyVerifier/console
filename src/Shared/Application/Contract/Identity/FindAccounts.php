<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Identity;

use Shared\Application\Bus\Query;

/**
 * Published query of Identity: email and status of accounts by id (to show members). Ids the actor may list are
 * decided by the caller; no secret is ever returned.
 *
 * @implements Query<array<string, AccountView>>
 */
final readonly class FindAccounts implements Query
{
    /**
     * @param list<string> $userIds
     */
    public function __construct(public array $userIds)
    {
    }
}
