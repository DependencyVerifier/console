<?php

declare(strict_types=1);

namespace Identity\Application\Query\Passkey;

use Identity\Application\Port\PasskeyOptions;
use Shared\Application\Bus\Query;

/**
 * The options a browser signs a challenge with: for the account holder (second factor, authenticating again: its
 * passkeys), or for anyone (signing in: the authenticator offers its discoverable passkeys).
 *
 * @implements Query<PasskeyOptions>
 */
final readonly class GetPasskeyRequestOptions implements Query
{
    public function __construct(public ?string $userId = null)
    {
    }
}
