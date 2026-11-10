<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Identity;

use Shared\Application\Bus\Query;

/**
 * Published query of Identity, for the account pickers of the administration: accounts that may be given a role
 * (active, allowed to sign in, invited ones included) whose email or display name starts with the term. Needs the
 * permission to manage the instance users.
 *
 * @implements Query<list<AccountMatch>>
 */
final readonly class SearchAccounts implements Query
{
    public const int MIN_LENGTH = 2;

    public const int MAX_RESULTS = 10;

    public function __construct(public string $term)
    {
    }
}
