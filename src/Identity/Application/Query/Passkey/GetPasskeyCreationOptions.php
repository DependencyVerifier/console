<?php

declare(strict_types=1);

namespace Identity\Application\Query\Passkey;

use Identity\Application\Port\PasskeyOptions;
use Shared\Application\Bus\Query;

/**
 * The options the account holder's browser creates a passkey with (its passkeys excluded).
 *
 * @implements Query<PasskeyOptions>
 */
final readonly class GetPasskeyCreationOptions implements Query
{
    public function __construct(public string $userId)
    {
    }
}
