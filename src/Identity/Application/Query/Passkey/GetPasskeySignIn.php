<?php

declare(strict_types=1);

namespace Identity\Application\Query\Passkey;

use Shared\Application\Bus\Query;

/**
 * The account a passkey sign-in attempt opened (AuthenticateWithPasskey), only when accepted and recent; null
 * otherwise (a refused sign-in is no refused query: the command audited it).
 *
 * @implements Query<?string>
 */
final readonly class GetPasskeySignIn implements Query
{
    public function __construct(public string $attemptId)
    {
    }
}
